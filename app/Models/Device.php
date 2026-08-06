<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'installation_id',
    'machine_fingerprint_hash',
    'label',
    'platform',
    'status',
    'first_seen_at',
    'last_seen_at',
])]
#[Hidden(['machine_fingerprint_hash'])]
class Device extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<LicenseActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }
}
