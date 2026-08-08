<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Models\Concerns\BelongsToCabinet;
use Database\Factories\DoctorProfileFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'user_id',
    'doctor_name',
    'specialty',
    'specialty_code',
    'professional_identifier',
    'medical_order_number',
    'clinic_name',
    'phone',
    'email',
    'city',
    'full_address',
    'footer_extra_line',
    'logo_path',
    'specialty_locked_at',
    'consultation_duration',
    'consultation_fee_minor',
    'is_active',
])]
class DoctorProfile extends Model
{
    /** @use HasFactory<DoctorProfileFactory> */
    use BelongsToCabinet, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consultation_duration' => 'integer',
            'consultation_fee_minor' => 'integer',
            'is_active' => 'boolean',
            'specialty_locked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            if (filled($profile->specialty)) {
                $profile->specialty_code ??= str($profile->specialty)->slug('_')->toString();
                $profile->specialty_locked_at ??= now();
            }
        });

        static::updating(function (self $profile): void {
            $specialtyWasLocked = $profile->getOriginal('specialty_locked_at') !== null;

            if ($specialtyWasLocked && $profile->isDirty(['specialty', 'specialty_code'])) {
                throw new AuthorizationException('The medical specialty is locked and requires an audited administrative correction.');
            }

            if (! $specialtyWasLocked && $profile->isDirty('specialty') && filled($profile->specialty)) {
                $profile->specialty_code ??= str($profile->specialty)->slug('_')->toString();
                $profile->specialty_locked_at = now();
            }
        });
    }

    protected static function newFactory(): DoctorProfileFactory
    {
        return DoctorProfileFactory::new();
    }

    /**
     * Resolve the single active cabinet doctor profile.
     */
    public static function current(): ?self
    {
        return static::query()->active()->first();
    }

    /**
     * Correct a locked specialty through an explicit, audited administrator path.
     * A user-facing workflow can call this method in a later phase.
     *
     * @throws AuthorizationException
     */
    public function correctLockedSpecialty(string $specialty, string $specialtyCode, User $actor): void
    {
        if (! $actor->hasAnyRole([
            RoleName::SUPER_ADMINISTRATOR->value,
            RoleName::ADMINISTRATOR->value,
        ])) {
            throw new AuthorizationException('Only an administrator may correct a locked medical specialty.');
        }

        DB::transaction(function () use ($specialty, $specialtyCode, $actor): void {
            $previous = [
                'specialty' => $this->specialty,
                'specialty_code' => $this->specialty_code,
            ];

            $this->forceFill([
                'specialty' => trim($specialty),
                'specialty_code' => trim($specialtyCode),
                'specialty_locked_at' => now(),
            ])->saveQuietly();

            AuditLog::record('doctor.specialty_corrected', $this, [
                'previous' => $previous,
                'current' => [
                    'specialty' => $this->specialty,
                    'specialty_code' => $this->specialty_code,
                ],
            ], $actor->getKey());
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DoctorSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class, 'doctor_id');
    }

    /** @return HasMany<DoctorTimeOff, $this> */
    public function timeOff(): HasMany
    {
        return $this->hasMany(DoctorTimeOff::class, 'doctor_id');
    }

    /** @return HasMany<DoctorOpenMonth, $this> */
    public function openMonths(): HasMany
    {
        return $this->hasMany(DoctorOpenMonth::class, 'doctor_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
