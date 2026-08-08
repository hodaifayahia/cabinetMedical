<?php

namespace App\Enums;

enum CabinetStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::ACTIVE => 'Actif',
            self::SUSPENDED => 'Suspendu',
        };
    }

    /**
     * Colour token understood by Filament badges / Inertia UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::ACTIVE => 'success',
            self::SUSPENDED => 'danger',
        };
    }
}
