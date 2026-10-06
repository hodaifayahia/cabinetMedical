<?php

namespace App\Support\Patients;

/**
 * Choices offered for the social fields of a dossier (situation familiale,
 * tabagisme), with the French labels shown on every screen.
 */
final class PatientDossierOptions
{
    private const MARITAL = [
        'single' => 'Célibataire',
        'married' => 'Marié(e)',
        'divorced' => 'Divorcé(e)',
        'widowed' => 'Veuf / veuve',
    ];

    private const SMOKING = [
        'non_smoker' => 'Non-fumeur',
        'smoker' => 'Fumeur',
        'former_smoker' => 'Ancien fumeur',
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function maritalStatuses(): array
    {
        return self::options(self::MARITAL);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function smokingStatuses(): array
    {
        return self::options(self::SMOKING);
    }

    /**
     * The French label of a stored value; free text typed before the lists
     * existed is returned as is.
     */
    public static function label(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::MARITAL[$value] ?? self::SMOKING[$value] ?? $value;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        $options = [];

        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
