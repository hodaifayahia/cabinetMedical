<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Services\Authorization\CabinetRolePermissionService;
use BackedEnum;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $public_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $local_pin_hash
 * @property string|null $account_recovery_codes
 * @property Carbon|null $account_recovery_codes_generated_at
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
#[Fillable(['name', 'email', 'phone', 'password', 'cabinet_setting_id', 'cabinet_id', 'is_platform_admin', 'approved_at'])]
#[Hidden(['public_id', 'password', 'local_pin_hash', 'account_recovery_codes', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable {
        HasRoles::getAllPermissions as private getAllPermissionsUsingGlobalRoles;
        HasRoles::hasPermissionTo as private hasPermissionToUsingGlobalRoles;
    }

    /**
     * Apply cabinet-specific role profiles to every Gate and permission
     * middleware check without mutating Spatie's globally seeded role rows.
     */
    public function hasPermissionTo(
        string|int|PermissionContract|BackedEnum $permission,
        ?string $guardName = null,
    ): bool {
        if ($this->cabinet_id === null) {
            return $this->hasPermissionToUsingGlobalRoles($permission, $guardName);
        }

        $storedPermission = $this->filterPermission($permission, $guardName);

        return app(CabinetRolePermissionService::class)->allows($this, $storedPermission);
    }

    /** @return Collection<int, PermissionContract> */
    public function getAllPermissions(): Collection
    {
        if ($this->cabinet_id === null) {
            return $this->getAllPermissionsUsingGlobalRoles();
        }

        return app(CabinetRolePermissionService::class)->effectivePermissions($this);
    }

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
            'account_recovery_codes_generated_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (User $user): void {
            if (blank($user->getAttribute('public_id'))) {
                $user->setAttribute('public_id', (string) Str::uuid7());
            }
        });

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
     * The demographic profile of a mobile patient account.
     *
     * @return HasOne<PatientProfile, $this>
     */
    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    /**
     * Family circle rows this patient account owns.
     *
     * @return HasMany<FamilyMember, $this>
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'owner_user_id');
    }

    /**
     * @return HasMany<DevicePushToken, $this>
     */
    public function devicePushTokens(): HasMany
    {
        return $this->hasMany(DevicePushToken::class);
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

    /**
     * The role a mobile client should act as. Precedence: platform admin,
     * then Patient, then Doctor; every other cabinet member is reception.
     */
    public function mobileRole(): string
    {
        return match (true) {
            $this->is_platform_admin === true => 'admin',
            $this->hasRole(RoleName::PATIENT->value) => 'patient',
            $this->hasRole(RoleName::DOCTOR->value) => 'doctor',
            default => 'reception',
        };
    }

    public function isMobilePatient(): bool
    {
        return $this->hasRole(RoleName::PATIENT->value);
    }
}
