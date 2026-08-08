<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PermissionName;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $local_pin_hash
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $cabinet_id
 * @property int|null $cabinet_setting_id
 * @property bool $is_platform_admin
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'cabinet_setting_id', 'cabinet_id', 'is_platform_admin', 'approved_at'])]
#[Hidden(['password', 'local_pin_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(static function (User $user): void {
            if (! $user->wasChanged('password')) {
                return;
            }

            $revoked = DesktopPinCredential::withoutCabinetScope()
                ->where('user_id', $user->getKey())
                ->delete();

            if ($revoked > 0) {
                AuditLog::record('security.desktop_pin_credentials_revoked', $user, [
                    'reason' => 'password_changed',
                    'credentials_revoked' => $revoked,
                ], $user->getKey());
            }
        });
    }

    /**
     * @return HasOne<DoctorProfile, $this>
     */
    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    /**
     * @return HasMany<DesktopPinCredential, $this>
     */
    public function desktopPinCredentials(): HasMany
    {
        return $this->hasMany(DesktopPinCredential::class);
    }

    /**
     * The tenant cabinet this user belongs to.
     *
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class, 'cabinet_id');
    }

    /**
     * The per-cabinet settings row (legacy link retained for compatibility).
     *
     * @return BelongsTo<CabinetSetting, $this>
     */
    public function cabinetSettings(): BelongsTo
    {
        return $this->belongsTo(CabinetSetting::class, 'cabinet_setting_id');
    }

    /**
     * The Filament back office is reserved for platform staff. Cabinet owners
     * and members manage their clinic through the Inertia application instead.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->is_platform_admin === true;
    }

    public function canAccessAdminPanel(): bool
    {
        return $this->is_platform_admin === true;
    }

    public function canManageStaff(): bool
    {
        return $this->can(PermissionName::STAFF_MANAGE->value);
    }

    /**
     * A member is approved once an owner has assigned them a role, or when the
     * account was created directly (owners, platform staff, legacy accounts).
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isPendingApproval(): bool
    {
        return $this->cabinet_id !== null && $this->approved_at === null;
    }
}
