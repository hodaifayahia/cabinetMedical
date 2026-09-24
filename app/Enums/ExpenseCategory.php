<?php

namespace App\Enums;

/**
 * Usual operating costs of a private practice in Algeria.
 */
enum ExpenseCategory: string
{
    case RENT = 'rent';
    case SALARIES = 'salaries';
    case SOCIAL_CONTRIBUTIONS = 'social_contributions';
    case MEDICAL_SUPPLIES = 'medical_supplies';
    case OFFICE_SUPPLIES = 'office_supplies';
    case UTILITIES = 'utilities';
    case TELECOM = 'telecom';
    case TAXES = 'taxes';
    case INSURANCE = 'insurance';
    case EQUIPMENT = 'equipment';
    case MAINTENANCE = 'maintenance';
    case PROFESSIONAL_FEES = 'professional_fees';
    case TRANSPORT = 'transport';
    case TRAINING = 'training';
    case BANK_FEES = 'bank_fees';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RENT => 'Loyer',
            self::SALARIES => 'Salaires',
            self::SOCIAL_CONTRIBUTIONS => 'Cotisations sociales (CNAS, CASNOS)',
            self::MEDICAL_SUPPLIES => 'Consommables médicaux',
            self::OFFICE_SUPPLIES => 'Fournitures de bureau',
            self::UTILITIES => 'Électricité, eau, gaz',
            self::TELECOM => 'Téléphone et internet',
            self::TAXES => 'Impôts et taxes (IRG, IFU…)',
            self::INSURANCE => 'Assurances',
            self::EQUIPMENT => 'Matériel et équipement',
            self::MAINTENANCE => 'Entretien et réparations',
            self::PROFESSIONAL_FEES => 'Honoraires (comptable, avocat…)',
            self::TRANSPORT => 'Transport et déplacements',
            self::TRAINING => 'Formation et congrès',
            self::BANK_FEES => 'Frais bancaires',
            self::OTHER => 'Autres charges',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
