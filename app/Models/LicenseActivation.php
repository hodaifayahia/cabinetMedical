<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'license_id',
    'device_id',
    'installation_id',
    'machine_fingerprint_hash',
    'activated_at',
    'last_seen_at',
    'deactivated_at',
    'status',
])]
#[Hidden(['machine_fingerprint_hash'])]
class LicenseActivation extends Model
{
    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
