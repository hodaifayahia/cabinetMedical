<?php

namespace App\Enums;

enum PatientAlertType: string
{
    case ALLERGY = 'allergy';
    case CONDITION = 'condition';
    case TREATMENT = 'treatment';

    public function label(): string
    {
        return match ($this) {
            self::ALLERGY => 'Allergie',
            self::CONDITION => 'Maladie chronique',
            self::TREATMENT => 'Traitement au long cours',
        };
    }
}
