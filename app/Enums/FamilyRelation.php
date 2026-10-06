<?php

namespace App\Enums;

enum FamilyRelation: string
{
    case FATHER = 'father';
    case MOTHER = 'mother';
    case HUSBAND = 'husband';
    case WIFE = 'wife';
    case SON = 'son';
    case DAUGHTER = 'daughter';
    case BROTHER = 'brother';
    case SISTER = 'sister';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FATHER => 'Père',
            self::MOTHER => 'Mère',
            self::HUSBAND => 'Époux',
            self::WIFE => 'Épouse',
            self::SON => 'Fils',
            self::DAUGHTER => 'Fille',
            self::BROTHER => 'Frère',
            self::SISTER => 'Sœur',
            self::OTHER => 'Autre',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $relation): string => $relation->value,
            self::cases(),
        );
    }
}
