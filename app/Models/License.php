<?php

namespace App\Models;

use App\Enums\LicensePlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $offline_grace_until
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $last_verified_at
 * @property array<string, mixed>|null $last_server_response
 * @property LicensePlan|null $plan
 * @property int|null $license_type_id
 */
#[Fillable([
    'license_id',
    'product',
    'edition',
    'plan',
    'license_type_id',
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
            'plan' => LicensePlan::class,
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

    /** @return BelongsTo<LicenseType, $this> */
    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    /** @return HasOne<Cabinet, $this> */
    public function cabinet(): HasOne
    {
        return $this->hasOne(Cabinet::class);
    }

    public function isHostedEntitlement(): bool
    {
        return $this->plan instanceof LicensePlan || $this->edition === 'hosted';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    public function typeLabel(): string
    {
        return $this->licenseType?->name ?? $this->plan?->label() ?? 'Licence';
    }

    /**
     * Expiry is computed from the clock so a seven-day trial is denied at its
     * exact expiry instant even before a maintenance job persists "expired".
     */
    public function effectiveStatus(): string
    {
        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->status;
    }

    public function effectiveStatusLabel(): string
    {
        return match ($this->effectiveStatus()) {
            'active' => 'Active',
            'expired' => 'Expirée',
            'suspended' => 'Suspendue',
            'revoked' => 'Révoquée',
            default => 'Inactive',
        };
    }
}
