<?php

namespace App\Models;

use App\Enums\EncounterStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property EncounterStatus $status
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $signed_at
 */
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'provider_id',
        'status',
        'occurred_at',
        'started_at',
        'signed_at',
        'signed_by',
        'revision_number',
        'amends_encounter_id',
        'amendment_reason',
        'content_hash',
        'lock_version',
    ];

    protected $casts = [
        'status' => EncounterStatus::class,
        'occurred_at' => 'datetime',
        'started_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->whereNull('deleted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function amendsEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'amends_encounter_id');
    }

    /**
     * @return HasMany<EncounterNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(EncounterNote::class);
    }

    /**
     * @return HasMany<Diagnosis, $this>
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    /**
     * @return HasMany<ClinicalObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicalObservation::class);
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(Encounter::class, 'amends_encounter_id');
    }

    public function isSigned(): bool
    {
        return $this->status === EncounterStatus::Signed;
    }

    public function isDraft(): bool
    {
        return $this->status === EncounterStatus::Draft;
    }

    public function canBeSigned(): bool
    {
        return in_array($this->status, [
            EncounterStatus::Draft,
            EncounterStatus::InProgress,
        ]);
    }
}
