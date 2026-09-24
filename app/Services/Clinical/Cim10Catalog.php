<?php

namespace App\Services\Clinical;

use Illuminate\Support\Str;

/**
 * The CIM-10 (ICD-10) codes a practice uses most, with French labels,
 * from database/data/cim10.json.
 */
final class Cim10Catalog
{
    /** @var array<string, array{code: string, label: string, search: string}>|null */
    private ?array $entries = null;

    /**
     * Codes whose code starts with the query, or whose label contains every
     * word of it (accents and case ignored).
     *
     * @return list<array{code: string, label: string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $normalized = $this->normalize($query);
        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
        $codeQuery = strtoupper(str_replace(' ', '', $query));
        $byCode = [];
        $byLabel = [];

        foreach ($this->entries() as $entry) {
            if (str_starts_with(strtoupper($entry['code']), $codeQuery)) {
                $byCode[] = ['code' => $entry['code'], 'label' => $entry['label']];

                continue;
            }

            $matchesAll = $words !== [];

            foreach ($words as $word) {
                if (! str_contains($entry['search'], $word)) {
                    $matchesAll = false;

                    break;
                }
            }

            if ($matchesAll) {
                $byLabel[] = ['code' => $entry['code'], 'label' => $entry['label']];
            }
        }

        return array_slice([...$byCode, ...$byLabel], 0, $limit);
    }

    /**
     * @return array{code: string, label: string}|null
     */
    public function find(string $code): ?array
    {
        $entry = $this->entries()[strtoupper(trim($code))] ?? null;

        return $entry === null ? null : ['code' => $entry['code'], 'label' => $entry['label']];
    }

    /**
     * @return array<string, array{code: string, label: string, search: string}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $raw = json_decode((string) file_get_contents(database_path('data/cim10.json')), true);
        $this->entries = [];

        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null) || ! is_string($row['label'] ?? null)) {
                continue;
            }

            $this->entries[strtoupper($row['code'])] = [
                'code' => $row['code'],
                'label' => $row['label'],
                'search' => $this->normalize($row['code'].' '.$row['label']),
            ];
        }

        return $this->entries;
    }

    private function normalize(string $text): string
    {
        return Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9. ]+/', ' ')->squish()->toString();
    }
}
