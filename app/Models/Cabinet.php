<?php

namespace App\Models;

use App\Enums\CabinetStatus;
use App\Enums\FacilityType;
use App\Enums\LicensePlan;
use App\Support\Wilayas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property CabinetStatus $status
 * @property string|null $specialization
 * @property FacilityType $facility_type
 * @property int|null $wilaya_code
 * @property int|null $owner_user_id
 * @property int|null $license_id
 * @property CarbonImmutable|null $activated_at
 * @property-read string|null $wilaya_name
 */
#[Fillable([
    'name',
    'status',
    'specialization',
    'facility_type',
    'wilaya_code',
    'owner_user_id',
    'activated_at',
    'license_id',
])]
class Cabinet extends Model
{
    /**
     * Maximum number of seats (members) a single cabinet may hold. Both
     * approved and pending-approval members count toward this limit.
     */
    public const MAX_SEATS = 3;

    protected function casts(): array
    {
        return [
            'status' => CabinetStatus::class,
            'facility_type' => FacilityType::class,
            'wilaya_code' => 'integer',
            'activated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(static function (Cabinet $cabinet): void {
            if (! $cabinet->wasChanged('status') || ! $cabinet->isSuspended()) {
                return;
            }

            $revoked = DesktopPinCredential::withoutCabinetScope()
                ->where('cabinet_id', $cabinet->getKey())
                ->delete();

            if ($revoked > 0) {
                AuditLog::record('security.desktop_pin_credentials_revoked', $cabinet, [
                    'reason' => 'cabinet_suspended',
                    'credentials_revoked' => $revoked,
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<HostedLicenseGrant, $this>
     */
    public function hostedLicenseGrants(): HasMany
    {
        return $this->hasMany(HostedLicenseGrant::class);
    }

    /**
     * @return HasMany<DesktopPinCredential, $this>
     */
    public function desktopPinCredentials(): HasMany
    {
        return $this->hasMany(DesktopPinCredential::class);
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * @return HasOne<CabinetPublicProfile, $this>
     */
    public function publicProfile(): HasOne
    {
        return $this->hasOne(CabinetPublicProfile::class)->withoutGlobalScopes();
    }

    /**
     * @return HasOne<CabinetSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(CabinetSetting::class);
    }

    /**
     * Cabinets a platform admin may currently hand an activation code to:
     * a pending one that owns no entitlement yet, or an active trial that
     * can still be renewed or upgraded in place.
     *
     * @param  Builder<Cabinet>  $query
     */
    public function scopeAwaitingActivationCode(Builder $query): void
    {
        $query->where(function (Builder $eligible): void {
            $eligible
                ->where(fn (Builder $pending): Builder => $pending
                    ->where('status', CabinetStatus::PENDING->value)
                    ->whereNull('license_id'))
                ->orWhere(fn (Builder $renewable): Builder => $renewable
                    ->where('status', CabinetStatus::ACTIVE->value)
                    ->whereHas('license', fn (Builder $license): Builder => $license
                        ->where('plan', LicensePlan::TRIAL->value)
                        ->where('status', '!=', 'revoked')));
        });
    }

    public function isActive(): bool
    {
        return $this->status === CabinetStatus::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === CabinetStatus::PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->status === CabinetStatus::SUSPENDED;
    }

    /**
     * Number of seats currently occupied. A seat is any user attached to the
     * cabinet regardless of approval state, so pending members reserve a seat.
     */
    public function seatsInUse(): int
    {
        return $this->users()->count();
    }

    public function hasAvailableSeat(): bool
    {
        return $this->seatsInUse() < self::MAX_SEATS;
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function wilayaName(): Attribute
    {
        return Attribute::get(fn (): ?string => Wilayas::name($this->wilaya_code));
    }
}
