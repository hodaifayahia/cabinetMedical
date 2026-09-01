<?php

namespace App\Enums;

enum FamilyMemberStatus: string
{
    case ACTIVE = 'active';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DECLINED = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Actif',
            self::PENDING => 'En attente',
            self::APPROVED => 'Approuvé',
            self::DECLINED => 'Refusé',
        };
    }

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
}
