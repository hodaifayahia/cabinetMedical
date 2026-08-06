<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cabinet_setting_id',
    'email',
    'folder_name',
    'folder_id',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'last_backup_at',
    'last_backup_name',
])]
class DriveBackupConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_backup_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CabinetSetting, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(CabinetSetting::class, 'cabinet_setting_id');
    }
}
