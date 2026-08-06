<?php

namespace App\Enums;

enum EncounterStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Signed = 'signed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InProgress => 'In Progress',
            self::Signed => 'Signed',
            self::Void => 'Void',
        };
    }
}
