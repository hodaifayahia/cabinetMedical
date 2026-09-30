<?php

namespace App\Models;

use App\Enums\ServerBackupDriveStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One encrypted nightly dump of the online service's database.
 *
 * @property ServerBackupDriveStatus $drive_status
 */
#[Fillable([
    'filename',
    'path',
    'size_bytes',
    'sha256',
    'database_driver',
    'drive_status',
    'drive_file_id',
    'drive_uploaded_at',
    'drive_error',
    'pc_copied_at',
])]
class ServerBackupRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'drive_status' => ServerBackupDriveStatus::class,
            'drive_uploaded_at' => 'datetime',
            'pc_copied_at' => 'datetime',
        ];
    }
}
