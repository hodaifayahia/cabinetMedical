<?php

namespace App\Enums;

enum DiagnosisStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';
    case Ruled_Out = 'ruled_out';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Resolved => 'Resolved',
            self::Ruled_Out => 'Ruled Out',
        };
    }
}
