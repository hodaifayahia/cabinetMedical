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
 * @property string $code_hash
 * @property string $code_suffix
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable([
    'cabinet_id',
    'issued_by_user_id',
    'redeemed_by_user_id',
    'revoked_by_user_id',
    'plan',
    'code_hash',
    'code_suffix',
    'redeemed_at',
    'revoked_at',
])]
#[Hidden(['code_hash'])]
class HostedLicenseGrant extends Model
{
    use BelongsToCabinet, HasUuids;

    protected function casts(): array
    {
        return [
            'plan' => LicensePlan::class,
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
