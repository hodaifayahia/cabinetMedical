<?php

namespace App\Models;

use App\Enums\LicensePlan;
use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cabinet-bound authorization to mint or update one hosted entitlement.
 * Only a keyed digest and a short display suffix are persisted; the plaintext
 * code exists only while it is delivered to the platform admin and owner.
 *
 * @property string $id
 * @property int $cabinet_id
 * @property LicensePlan $plan
 * @property LicenseType|null $licenseType
 * @property int|null $duration_days
 * @property string|null $type_name
 * @property string $code_hash
 * @property string|null $code_encrypted
 * @property string $code_suffix
 * @property CarbonImmutable|null $redeemed_at
 * @property string|null $redeemed_installation_id
 * @property string|null $redeemed_owner_email
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable([
    'cabinet_id',
    'issued_by_user_id',
    'redeemed_by_user_id',
    'revoked_by_user_id',
    'plan',
    'license_type_id',
    'duration_days',
    'type_name',
    'code_hash',
    'code_encrypted',
    'code_suffix',
    'redeemed_at',
    'redeemed_installation_id',
    'redeemed_owner_email',
    'revoked_at',
])]
#[Hidden(['code_hash', 'code_encrypted'])]
class HostedLicenseGrant extends Model
{
    use BelongsToCabinet, HasUuids;

    protected function casts(): array
    {
        return [
            'plan' => LicensePlan::class,
            'code_encrypted' => 'encrypted',
            'duration_days' => 'integer',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<HostedLicenseGrant> $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('redeemed_at')->whereNull('revoked_at');
    }

    public function isOutstanding(): bool
    {
        return $this->redeemed_at === null && $this->revoked_at === null;
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->redeemed_at !== null ? 'redeemed' : 'outstanding';
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            'revoked' => 'Révoquée',
            'redeemed' => 'Utilisée',
            default => 'En attente',
        };
    }

    /**
     * The masked form is safe to render in a list; the plaintext is only
     * released through an explicit, audited reveal by a platform admin.
     */
    public function maskedCode(): string
    {
        return 'DRDZ-…-'.$this->code_suffix;
    }

    /**
     * Grants issued before recoverable storage existed keep only their
     * digest, so their plaintext is genuinely unavailable.
     */
    public function plainCode(): ?string
    {
        $code = $this->code_encrypted;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** @return BelongsTo<LicenseType, $this> */
    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function typeLabel(): string
    {
        return $this->type_name ?? $this->licenseType?->name ?? $this->plan?->label() ?? 'Licence';
    }

    public function expiresAt(CarbonImmutable $startsAt): ?CarbonImmutable
    {
        return $this->duration_days !== null
            ? $startsAt->addDays($this->duration_days)
            : $this->plan?->expiresAt($startsAt);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
