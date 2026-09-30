<?php

namespace App\Enums;

/**
 * Where a nightly server backup stands with respect to its Google Drive copy.
 */
enum ServerBackupDriveStatus: string
{
    case PENDING = 'pending';
    case UPLOADED = 'uploaded';
    case FAILED = 'failed';
    // No Drive account was connected when the backup was made.
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::UPLOADED => 'Envoyée',
            self::FAILED => 'Échec',
            self::SKIPPED => 'Drive non connecté',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::UPLOADED => 'success',
            self::FAILED => 'danger',
            self::SKIPPED => 'warning',
        };
    }
}
