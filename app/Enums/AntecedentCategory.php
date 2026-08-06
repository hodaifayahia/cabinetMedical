<?php

namespace App\Enums;

enum AntecedentCategory: string
{
    case Medical = 'medical';
    case Surgical = 'surgical';
    case Family = 'family';
    case GynecoObstetric = 'gyneco_obstetric';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Medical => 'Medical',
            self::Surgical => 'Surgical',
            self::Family => 'Family',
            self::GynecoObstetric => 'Gyneco-obstetric',
            self::Other => 'Other',
        };
    }
}
