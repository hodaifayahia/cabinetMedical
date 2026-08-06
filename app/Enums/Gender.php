<?php

namespace App\Enums;

enum Gender: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';
    case UNDISCLOSED = 'undisclosed';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Homme',
            self::FEMALE => 'Femme',
            self::OTHER => 'Autre',
            self::UNDISCLOSED => 'Non renseigné',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $gender): string => $gender->value,
            self::cases(),
        );
    }
}
