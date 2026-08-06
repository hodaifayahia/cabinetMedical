<?php

namespace App\Support;

use Illuminate\Support\Str;

final class MedicalSpecialtyCatalog
{
    /** @var array<string, string> */
    private const LABELS = [
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

    /** @return list<string> */
    public function labels(): array
    {
        return array_values(self::LABELS);
    }

    public function display(?string $specialty, ?string $code = null): string
    {
        $specialty = trim((string) $specialty);
        $code = trim((string) $code);

        if ($code !== '' && isset(self::LABELS[$code])) {
            return self::LABELS[$code];
        }

        $knownCode = $this->knownCodeForLabel($specialty);

        return $knownCode === null ? $specialty : self::LABELS[$knownCode];
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

    private function knownCodeForLabel(string $specialty): ?string
    {
        $normalized = Str::lower(trim($specialty));

        if (isset(self::LEGACY_ALIASES[$normalized])) {
            return self::LEGACY_ALIASES[$normalized];
        }

        foreach (self::LABELS as $code => $label) {
            if (Str::lower($label) === $normalized) {
                return $code;
            }
        }

        return null;
    }
}
