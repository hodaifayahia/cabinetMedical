<?php

namespace App\Enums;

enum RoleName: string
{
    case DOCTOR = 'Doctor';
    case ASSISTANT = 'Assistant';
    case PATIENT = 'Patient';

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
            self::PATIENT => 'Patient',
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

    /**
     * Cabinet staff roles only — the mobile Patient role never appears in
     * staff management screens, role matrices, or staff role assignment.
     *
     * @return list<self>
     */
    public static function staffCases(): array
    {
        $cases = [];

        foreach (self::cases() as $role) {
            if ($role !== self::PATIENT) {
                $cases[] = $role;
            }
        }

        return $cases;
    }

    /** @return list<string> */
    public static function staffValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::staffCases());
    }
}
