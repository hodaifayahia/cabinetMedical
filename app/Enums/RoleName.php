<?php

namespace App\Enums;

enum RoleName: string
{
    case DOCTOR = 'Doctor';
    case ASSISTANT = 'Assistant';

    // Compatibility aliases. They are not additional roles and are absent
    // from cases() and values().
    public const SUPER_ADMINISTRATOR = self::DOCTOR;
    public const ADMINISTRATOR = self::DOCTOR;
    public const RECEPTIONIST = self::ASSISTANT;
    public const CASHIER = self::ASSISTANT;
    public const STOCK_MANAGER = self::ASSISTANT;
    public const PHARMACIST = self::ASSISTANT;

    public function label(): string
    {
        return match ($this) {
            self::DOCTOR => 'Médecin (Super administrateur)',
            self::ASSISTANT => 'Assistant',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /** @return list<string> */
    public static function adminPanelValues(): array
    {
        return [self::DOCTOR->value];
    }
}
