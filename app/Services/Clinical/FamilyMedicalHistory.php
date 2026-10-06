<?php

namespace App\Services\Clinical;

use App\Enums\PatientAlertType;
use App\Enums\PatientRelation;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientRelative;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the patient's relatives are known for: their allergies, chronic
 * diseases, operations and recent diagnoses, read from their own dossiers so
 * the doctor sees « Frère — Ahmed B. : Diabète type 2 » without typing it.
 *
 * Relatives are the dossiers the cabinet linked to the patient, plus the
 * dossiers booked through the same mobile family account.
 */
final class FamilyMedicalHistory
{
    /** Free-text answers that mean « nothing to report ». */
    private const EMPTY_ANSWERS = [
        'ras', 'r.a.s', 'neant', 'aucun', 'aucune', 'rien', 'non', 'nr', 'nc', '0', 'na', 'n/a',
        'aucun antecedent', 'aucun antecedent particulier', 'pas d\'antecedent', 'pas d antecedent',
        'aucune allergie', 'aucune allergie connue', 'pas d\'allergie', 'pas d allergie',
        'sans particularite', 'sans particularites', 'rien a signaler', 'non connu', 'inconnu',
    ];

    /** Order in which relatives are listed: closest first. */
    private const KIND_ORDER = ['parent' => 0, 'sibling' => 1, 'child' => 2, 'spouse' => 3, 'grandparent' => 4, 'grandchild' => 5, 'uncle' => 6, 'nephew' => 7, 'cousin' => 8, 'other' => 9];

    public function __construct(private readonly PatientSafety $safety) {}

    /**
     * The patient's relatives (active dossiers of the same cabinet), each
     * with how they are related to the patient.
     *
     * @return array<int, array{patient: Patient, relation: PatientRelation|null, source: string, link: PatientRelative|null}>
     */
    public function relatives(Patient $patient): array
    {
        $relatives = [];

        $links = PatientRelative::query()
            ->where('patient_id', $patient->getKey())
            ->with('relative')
            ->get();

        foreach ($links as $link) {
            $relative = $link->relative;

            // An archived (merged) dossier is no longer a relative.
            if ($relative instanceof Patient) {
                $relatives[(int) $relative->getKey()] = [
                    'patient' => $relative,
                    'relation' => $link->relation,
                    'source' => 'link',
                    'link' => $link,
                ];
            }
        }

        $group = $patient->family_group_public_id;

        if (filled($group)) {
            $members = Patient::query()
                ->where('family_group_public_id', $group)
                ->whereKeyNot($patient->getKey())
                ->whereNotIn('id', array_keys($relatives))
                ->get();

            foreach ($members as $member) {
                $relatives[(int) $member->getKey()] = [
                    'patient' => $member,
                    'relation' => $this->groupRelation($patient, $member),
                    'source' => 'mobile',
                    'link' => null,
                ];
            }
        }

        return $relatives;
    }

    /**
     * Every relative with what their dossier reports, closest relatives first.
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(Patient $patient): array
    {
        $relatives = $this->relatives($patient);

        if ($relatives === []) {
            return [];
        }

        $ids = array_keys($relatives);

        $alerts = PatientAlert::query()
            ->whereIn('patient_id', $ids)
            ->where('is_active', true)
            ->whereIn('type', [PatientAlertType::ALLERGY->value, PatientAlertType::CONDITION->value])
            ->orderBy('label')
            ->get()
            ->groupBy('patient_id');

        $since = now()->subYears(3);

        $coded = ConsultationDiagnosis::query()
            ->whereIn('patient_id', $ids)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get(['patient_id', 'code', 'label'])
            ->groupBy('patient_id');

        $written = Consultation::query()
            ->whereIn('patient_id', $ids)
            ->where('consulted_at', '>=', $since)
            ->whereNotNull('diagnostic')
            ->orderByDesc('consulted_at')
            ->limit(200)
            ->get(['patient_id', 'diagnostic'])
            ->groupBy('patient_id');

        return array_values(collect($relatives)
            ->map(function (array $row) use ($alerts, $coded, $written): array {
                $relative = $row['patient'];
                $relation = $row['relation'];
                $firstDegree = $relation?->isFirstDegree() ?? false;
                $id = (int) $relative->getKey();

                $items = $this->items(
                    $relative,
                    $alerts->get($id, collect()),
                    $coded->get($id, collect()),
                    $written->get($id, collect()),
                    $firstDegree,
                );

                return [
                    'patient_id' => $id,
                    'full_name' => $relative->full_name,
                    'short_name' => $this->shortName($relative),
                    'patient_number' => $relative->patient_number,
                    'gender' => $relative->gender?->value,
                    'age' => $relative->date_of_birth !== null ? (int) $relative->date_of_birth->diffInYears(now()) : null,
                    'relation' => $relation?->value,
                    'relation_label' => $relation?->label() ?? 'Proche',
                    'first_degree' => $firstDegree,
                    'source' => $row['source'],
                    'link_id' => $row['link']?->public_id,
                    'items' => $items,
                    'summary' => $items === [] ? null : Str::limit(implode(' ; ', array_column($items, 'display')), 320),
                    'has_alert' => collect($items)->contains(
                        static fn (array $item): bool => in_array($item['category'], ['allergy', 'condition'], true),
                    ),
                ];
            })
            ->sortBy([
                static fn (array $a, array $b): int => self::KIND_ORDER[PatientRelation::tryFrom((string) $a['relation'])?->kind() ?? 'other']
                    <=> self::KIND_ORDER[PatientRelation::tryFrom((string) $b['relation'])?->kind() ?? 'other'],
                static fn (array $a, array $b): int => strcmp((string) $a['full_name'], (string) $b['full_name']),
            ])
            ->all());
    }

    /**
     * Plain-text lines for the AI context: relation and findings only, never
     * the relative's identity.
     *
     * @return list<string>
     */
    public function contextLines(Patient $patient): array
    {
        $lines = [];

        foreach ($this->forPatient($patient) as $relative) {
            if ($relative['summary'] !== null) {
                $lines[] = '- '.$relative['relation_label']
                    .($relative['age'] !== null ? ' ('.$relative['age'].' ans)' : '')
                    .' : '.$relative['summary'];
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, PatientAlert>  $alerts
     * @param  Collection<int, ConsultationDiagnosis>  $coded
     * @param  Collection<int, Consultation>  $written
     * @return list<array{category: string, label: string, text: string, display: string}>
     */
    private function items(Patient $relative, Collection $alerts, Collection $coded, Collection $written, bool $firstDegree): array
    {
        $items = [];
        $seen = [];
        $add = function (string $category, string $label, string $text, string $display) use (&$items, &$seen): void {
            $key = $category.'|'.Str::of($text)->ascii()->lower()->squish()->toString();

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $items[] = ['category' => $category, 'label' => $label, 'text' => $text, 'display' => $display];
        };

        foreach ($alerts->where('type', PatientAlertType::CONDITION) as $alert) {
            $add('condition', 'Maladie chronique', $alert->label, $alert->label);
        }

        $chronic = $this->meaningful($relative->getAttribute('antecedents_medical'));

        if ($chronic !== null) {
            $add('condition', 'Maladies chroniques / antécédents médicaux', $chronic, $chronic);
        }

        foreach ($alerts->where('type', PatientAlertType::ALLERGY) as $alert) {
            $text = $alert->label.($alert->severity !== null ? ' · '.mb_strtolower(PatientAlert::SEVERITIES[$alert->severity] ?? $alert->severity) : '');
            $add('allergy', 'Allergie', $text, 'Allergie : '.$text);
        }

        $allergies = $this->meaningful($relative->getAttribute('allergies'));

        if ($allergies !== null && $this->safety->allergenTerms($allergies) !== []) {
            $add('allergy', 'Allergies et réactions connues', $allergies, 'Allergie : '.$allergies);
        }

        $surgical = $this->meaningful($relative->getAttribute('antecedents_surgical'));

        if ($surgical !== null) {
            $add('surgical', 'Antécédents chirurgicaux', $surgical, 'Chirurgie : '.$surgical);
        }

        if ($firstDegree) {
            $family = $this->meaningful($relative->getAttribute('antecedents_family'));

            if ($family !== null) {
                $add('family', 'Ses antécédents familiaux', $family, 'Ses ATCD familiaux : '.$family);
            }
        }

        $diagnoses = $coded
            ->map(static fn (ConsultationDiagnosis $diagnosis): string => trim($diagnosis->label.' ('.$diagnosis->code.')'))
            ->unique()
            ->take(4);

        if ($diagnoses->isEmpty()) {
            $diagnoses = $written
                ->map(fn (Consultation $consultation): ?string => $this->meaningful($consultation->diagnostic, 120))
                ->filter()
                ->unique()
                ->take(2);
        }

        foreach ($diagnoses as $diagnosis) {
            $add('diagnosis', 'Diagnostic récent', (string) $diagnosis, 'Diagnostic : '.$diagnosis);
        }

        return $items;
    }

    /**
     * How a dossier of the same mobile family account is related to the
     * patient. The mobile app records each dossier's relation to the account
     * holder (whose own dossier has none), so only some pairs can be deduced.
     */
    private function groupRelation(Patient $patient, Patient $member): ?PatientRelation
    {
        $mine = PatientRelation::tryFrom((string) $patient->family_relation);
        $theirs = PatientRelation::tryFrom((string) $member->family_relation);

        // The patient holds the account: the member's relation is to them.
        if ($mine === null && $theirs !== null && blank($patient->family_relation)) {
            return $theirs;
        }

        // The member holds the account: the patient's relation, reversed.
        if ($theirs === null && $mine !== null && blank($member->family_relation)) {
            return $mine->inverse($member->gender);
        }

        // Two children of the account holder are brothers and sisters.
        if ($mine?->kind() === 'child' && $theirs?->kind() === 'child') {
            return PatientRelation::ofKind('sibling', $member->gender);
        }

        return $theirs !== null && $theirs !== PatientRelation::OTHER ? $theirs : null;
    }

    private function shortName(Patient $patient): string
    {
        $first = trim((string) $patient->first_name);
        $last = trim((string) $patient->last_name);

        return trim($first.' '.($last !== '' ? mb_strtoupper(mb_substr($last, 0, 1)).'.' : ''));
    }

    /**
     * The text without markup, or null when it says nothing (« RAS »…).
     */
    private function meaningful(mixed $value, int $limit = 200): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $plain = Str::of(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $value))))
            ->squish()
            ->toString();
        $normalized = Str::of($plain)->ascii()->lower()->trim(' .-/;:,!')->toString();

        if ($normalized === '' || in_array($normalized, self::EMPTY_ANSWERS, true)) {
            return null;
        }

        return Str::limit($plain, $limit);
    }
}
