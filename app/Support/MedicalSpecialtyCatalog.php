<?php

namespace App\Support;

use App\Models\MedicalSpecialty;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The medical specialty catalogue.
 *
 * The platform admin manages it in the `medical_specialties` table: adding
 * specialties, correcting labels, and switching off the ones the patient app
 * should not offer as a search filter. BUILT_IN_LABELS seeded that table and
 * still backs the catalogue when the table is not there yet (mid-migration).
 *
 * Resolved once per request (see AppServiceProvider) and memoised; a write to
 * the table drops the memo (see MedicalSpecialty::booted()).
 */
final class MedicalSpecialtyCatalog
{
    /** @var array<string, string> */
    public const BUILT_IN_LABELS = [
        'general_medicine' => 'Médecine générale',
        'family_medicine' => 'Médecine familiale',
        'internal_medicine' => 'Médecine interne',
        'occupational_medicine' => 'Médecine du travail',
        'anesthesiology' => 'Anesthésie-réanimation',
        'cardiology' => 'Cardiologie',
        'general_surgery' => 'Chirurgie générale',
        'dermatology' => 'Dermatologie',
        'endocrinology' => 'Endocrinologie et diabétologie',
        'gastroenterology' => 'Gastro-entérologie',
        'obstetrics_gynecology' => 'Gynécologie-obstétrique',
        'nephrology' => 'Néphrologie',
        'neurology' => 'Neurologie',
        'ophthalmology' => 'Ophtalmologie',
        'otorhinolaryngology' => 'ORL',
        'pediatrics' => 'Pédiatrie',
        'pulmonology' => 'Pneumologie',
        'psychiatry' => 'Psychiatrie',
        'radiology' => 'Radiologie',
        'rheumatology' => 'Rhumatologie',
        'urology' => 'Urologie',
    ];

    /** @var array<string, string> */
    private const LEGACY_ALIASES = [
        'general medicine' => 'general_medicine',
        'family medicine' => 'family_medicine',
        'internal medicine' => 'internal_medicine',
        'occupational medicine' => 'occupational_medicine',
        'anesthesiology' => 'anesthesiology',
        'cardiology' => 'cardiology',
        'general surgery' => 'general_surgery',
        'dermatology' => 'dermatology',
        'endocrinology' => 'endocrinology',
        'gastroenterology' => 'gastroenterology',
        'obstetrics and gynecology' => 'obstetrics_gynecology',
        'nephrology' => 'nephrology',
        'neurology' => 'neurology',
        'ophthalmology' => 'ophthalmology',
        'otorhinolaryngology' => 'otorhinolaryngology',
        'pediatrics' => 'pediatrics',
        'pulmonology' => 'pulmonology',
        'psychiatry' => 'psychiatry',
        'radiology' => 'radiology',
        'rheumatology' => 'rheumatology',
        'urology' => 'urology',
    ];

    /** @var array<string, array{label_fr: string, label_ar: string|null, is_active: bool}>|null */
    private ?array $entries = null;

    /**
     * Every specialty's French label, active or not: the suggestions offered
     * when a clinic names its specialty. Switching a specialty off hides it
     * from the patient filter, not from the clinics that practise it.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['label_fr'],
            $this->entries(),
        ));
    }

    /**
     * The specialties the patient app offers as a search filter, in catalogue
     * order. An entry with no Arabic label falls back to the French one.
     *
     * @return list<array{code: string, label_fr: string, label_ar: string}>
     */
    public function directory(): array
    {
        $directory = [];

        foreach ($this->entries() as $code => $entry) {
            if (! $entry['is_active']) {
                continue;
            }

            $directory[] = [
                'code' => $code,
                'label_fr' => $entry['label_fr'],
                'label_ar' => $entry['label_ar'] ?? $entry['label_fr'],
            ];
        }

        return $directory;
    }

    /**
     * Every stored `specialty_code` that means this specialty: the canonical
     * code plus the French-derived variants older doctor profiles carry
     * ("pediatrie" for "pediatrics"). The patient filter matches all of them.
     *
     * @return list<string>
     */
    public function matchingCodes(string $code): array
    {
        $canonical = SpecialtyArabicLabels::canonicalCode($code) ?? $code;

        return array_values(array_unique([
            $canonical,
            $code,
            ...SpecialtyArabicLabels::aliasesOf($canonical),
        ]));
    }

    /** The admin-managed Arabic label of a catalogue code, if it has one. */
    public function arabicLabel(string $code): ?string
    {
        return $this->entries()[$code]['label_ar'] ?? null;
    }

    public function display(?string $specialty, ?string $code = null): string
    {
        $specialty = trim((string) $specialty);
        $code = trim((string) $code);

        if ($code !== '' && ($label = $this->frenchLabel($code)) !== null) {
            return $label;
        }

        $knownCode = $this->knownCodeForLabel($specialty);

        return $knownCode === null ? $specialty : ($this->frenchLabel($knownCode) ?? $specialty);
    }

    public function codeFor(string $specialty): string
    {
        $specialty = trim($specialty);
        $knownCode = $this->knownCodeForLabel($specialty);

        if ($knownCode !== null) {
            return $knownCode;
        }

        $slug = Str::of($specialty)->slug('_')->toString();

        return $slug !== ''
            ? $slug
            : 'specialty_'.substr(hash('sha256', Str::lower($specialty)), 0, 16);
    }

    private function frenchLabel(string $code): ?string
    {
        return $this->entries()[$code]['label_fr'] ?? self::BUILT_IN_LABELS[$code] ?? null;
    }

    private function knownCodeForLabel(string $specialty): ?string
    {
        $normalized = Str::lower(trim($specialty));

        if ($normalized === '') {
            return null;
        }

        if (isset(self::LEGACY_ALIASES[$normalized])) {
            return self::LEGACY_ALIASES[$normalized];
        }

        foreach ($this->entries() as $code => $entry) {
            if (Str::lower($entry['label_fr']) === $normalized) {
                return $code;
            }
        }

        // A built-in label an admin has since renamed still names its code, so
        // clinics that typed the original wording keep resolving to it.
        foreach (self::BUILT_IN_LABELS as $code => $label) {
            if (Str::lower($label) === $normalized) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{label_fr: string, label_ar: string|null, is_active: bool}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];

        try {
            // No database at all (a plain unit test): built-in catalogue below.
            $rows = Model::getConnectionResolver() === null
                ? []
                : MedicalSpecialty::query()->orderBy('id')->get(['code', 'label_fr', 'label_ar', 'is_active']);

            foreach ($rows as $row) {
                $entries[$row->code] = [
                    'label_fr' => $row->label_fr,
                    'label_ar' => $row->label_ar,
                    'is_active' => $row->is_active,
                ];
            }
        } catch (QueryException|BindingResolutionException) {
            // The table does not exist yet (a migration run in progress), or
            // the database is gone (a torn-down test app): built-in catalogue.
        }

        if ($entries === []) {
            $arabic = SpecialtyArabicLabels::map();

            foreach (self::BUILT_IN_LABELS as $code => $label) {
                $entries[$code] = ['label_fr' => $label, 'label_ar' => $arabic[$code] ?? null, 'is_active' => true];
            }
        }

        return $this->entries = $entries;
    }
}
