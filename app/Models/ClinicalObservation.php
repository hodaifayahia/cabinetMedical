<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $numeric_value
 * @property CarbonImmutable|null $observed_at
 */
class ClinicalObservation extends Model
{
    /** @use HasFactory<Factory<ClinicalObservation>> */
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'type',
        'numeric_value',
        'string_value',
        'unit',
        'observed_at',
        'source',
        'note',
        'created_by',
    ];

    protected $casts = [
        'numeric_value' => 'decimal:2',
        'observed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class)->whereNull('deleted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
