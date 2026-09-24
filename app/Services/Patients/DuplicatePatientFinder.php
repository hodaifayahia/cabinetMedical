<?php

namespace App\Services\Patients;

use App\Models\Consultation;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;

/**
 * Finds dossiers that probably describe the same person, so the cabinet can
 * merge them. Only suggests: nothing is merged without the doctor confirming.
 *
 * Two dossiers are grouped when they share, after removing accents, case and
 * punctuation:
 *   - the same first and last name (in either order — they are often swapped);
 *   - the same date of birth and last name;
 *   - the same phone number and first name (a phone alone is not enough:
 *     families share one).
 */
final class DuplicatePatientFinder
{
    private const PHONE_DIGITS = 9;

    /**
     * Groups of likely duplicates in the current cabinet, largest first.
     *
     * @return array<int, array{reasons: array<int, string>, patients: array<int, array<string, mixed>>}>
     */
    public function groups(int $limit = 50): array
    {
        $rows = $this->rows();
        $parent = [];
        $reasons = [];

        $find = function (int $id) use (&$parent, &$find): int {
            if (! isset($parent[$id]) || $parent[$id] === $id) {
                return $parent[$id] = $id;
            }

            return $parent[$id] = $find($parent[$id]);
        };

        foreach ($this->keys($rows) as [$reason, $ids]) {
            $root = $find($ids[0]);

            foreach (array_slice($ids, 1) as $id) {
                $parent[$find($id)] = $root;
            }

            foreach ($ids as $id) {
                $reasons[$id][$reason] = true;
            }
        }

        $grouped = [];

        foreach (array_keys($parent) as $id) {
            $grouped[$find($id)][] = $id;
        }

        $groups = collect($grouped)
            ->filter(static fn (array $ids): bool => count($ids) > 1)
            ->sortByDesc(static fn (array $ids): int => count($ids))
            ->take($limit)
            ->values();

        $summaries = $this->summaries(array_map('intval', $groups->flatten()->all()));

        return $groups->map(fn (array $ids): array => [
            'reasons' => collect($ids)->flatMap(static fn (int $id): array => array_keys($reasons[$id] ?? []))->unique()->values()->all(),
            'patients' => collect($ids)
                ->map(static fn (int $id): ?array => $summaries[$id] ?? null)
                ->filter()
                ->sortByDesc('visits_count')
                ->values()
                ->all(),
        ])->all();
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (array $group): int => count($group['patients']) - 1, $this->groups(500)));
    }

    /**
     * Likely duplicates of one patient, plus any dossier matching a search.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidatesFor(Patient $patient, string $search = ''): array
    {
        $ids = collect();

        if (trim($search) !== '') {
            $ids = Patient::query()->search($search)->whereKeyNot($patient->getKey())->limit(15)->pluck('id');
        } else {
            foreach ($this->groups(500) as $group) {
                $members = array_column($group['patients'], 'id');

                if (in_array($patient->getKey(), $members, true)) {
                    $ids = collect($members)->reject(static fn (int $id): bool => $id === $patient->getKey());
                    break;
                }
            }
        }

        $summaries = $this->summaries(array_map('intval', $ids->all()));

        return $ids->map(static fn (int $id): ?array => $summaries[$id] ?? null)->filter()->values()->all();
    }

    /**
     * Number of visits and date of the last one, keyed by patient id.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{count: int, last: string|null}>
     */
    public function visitStats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stats = [];
        $rows = Consultation::query()
            ->whereIn('patient_id', $ids)
            ->selectRaw('patient_id, count(*) as visits, max(consulted_at) as last_visit')
            ->groupBy('patient_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $stats[(int) $row->patient_id] = [
                'count' => (int) $row->visits,
                'last' => $row->last_visit !== null ? (string) $row->last_visit : null,
            ];
        }

        return $stats;
    }

    /**
     * The dossier as listed when finding and merging duplicates, keyed by id.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $visits = $this->visitStats($ids);

        return Patient::query()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(static function (Patient $patient) use ($visits): array {
                $visit = $visits[$patient->getKey()] ?? ['count' => 0, 'last' => null];

                return [$patient->getKey() => [
                    'id' => $patient->getKey(),
                    'patient_number' => $patient->patient_number,
                    'full_name' => $patient->full_name,
                    'date_of_birth' => $patient->date_of_birth?->toDateString(),
                    'gender' => $patient->gender?->value,
                    'phone' => $patient->phone,
                    'city' => $patient->city,
                    'created_at' => $patient->created_at?->toISOString(),
                    'visits_count' => $visit['count'],
                    'last_visit_at' => $visit['last'],
                ]];
            })
            ->all();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function rows(): Collection
    {
        return Patient::query()
            ->toBase()
            ->select(['id', 'first_name', 'last_name', 'date_of_birth', 'phone'])
            ->get();
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<array{0: string, 1: list<int>}>
     */
    private function keys(Collection $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $first = $this->normalise($row->first_name);
            $last = $this->normalise($row->last_name);
            $id = (int) $row->id;

            if ($first !== '' && $last !== '') {
                $names = [$first, $last];
                sort($names);
                $buckets['name|'.implode(' ', $names)][] = $id;
            }

            if ($last !== '' && ! empty($row->date_of_birth)) {
                $buckets['birth|'.substr((string) $row->date_of_birth, 0, 10).'|'.$last][] = $id;
            }

            $phone = substr((string) preg_replace('/\D+/', '', (string) $row->phone), -self::PHONE_DIGITS);

            if ($first !== '' && strlen($phone) === self::PHONE_DIGITS) {
                $buckets['phone|'.$phone.'|'.$first][] = $id;
            }
        }

        $labels = [
            'name' => 'Même nom',
            'birth' => 'Même date de naissance et nom',
            'phone' => 'Même téléphone et prénom',
        ];

        $keys = [];

        foreach ($buckets as $key => $ids) {
            if (count($ids) > 1) {
                $keys[] = [$labels[Str::before($key, '|')], $ids];
            }
        }

        return $keys;
    }

    private function normalise(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $value)));
    }
}
