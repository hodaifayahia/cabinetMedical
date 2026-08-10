<?php

namespace App\Enums;

enum RoleName: string
{
    case SUPER_ADMINISTRATOR = 'Super Administrator';
    case ADMINISTRATOR = 'Administrator';
    case DOCTOR = 'Doctor';
    case RECEPTIONIST = 'Receptionist';
    case CASHIER = 'Cashier';
    case STOCK_MANAGER = 'Stock Manager';
    case PHARMACIST = 'Pharmacist';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMINISTRATOR => 'Super administrateur',
            self::ADMINISTRATOR => 'Administrateur',
            self::DOCTOR => 'Médecin',
            self::RECEPTIONIST => 'Réceptionniste',
            self::CASHIER => 'Caissier',
            self::STOCK_MANAGER => 'Gestionnaire de stock',
            self::PHARMACIST => 'Pharmacien',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function adminPanelValues(): array
    {
        return [
            self::SUPER_ADMINISTRATOR->value,
            self::ADMINISTRATOR->value,
            self::STOCK_MANAGER->value,
        ];
    }
}
