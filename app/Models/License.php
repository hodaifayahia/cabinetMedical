<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $offline_grace_until
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $last_verified_at
 * @property array<string, mixed>|null $last_server_response
 */
#[Fillable([
    'license_id',
    'product',
    'edition',
    'customer_id',
    'signed_certificate',
    'status',
    'issued_at',
    'expires_at',
    'offline_grace_until',
    'last_verified_at',
    'last_server_response',
])]
#[Hidden(['signed_certificate', 'last_server_response'])]
class License extends Model
{
    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'offline_grace_until' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_server_response' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // The signed certificate is minted centrally; keep the NOT NULL column
        // satisfied for admin-registered records that are not signed yet.
        static::creating(static function (License $license): void {
            if ($license->signed_certificate === null) {
                $license->signed_certificate = '';
            }
        });
    }

    /** @return HasMany<LicenseActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }
}
