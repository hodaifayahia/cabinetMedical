<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $patient_id
 * @property CarbonImmutable|null $consulted_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $payment_amount_minor
 * @property bool $is_paid
 */
#[Fillable([
    'patient_id',
    'appointment_id',
    'consulted_at',
    'motif',
    'examens',
    'diagnostic',
    'traitement',
    'notes',
    'weight_kg',
    'height_cm',
    'temperature_c',
    'blood_pressure',
    'payment_amount_minor',
    'payment_method',
    'payment_service',
    'is_paid',
    'status',
    'completed_at',
    'created_by',
])]
class Consultation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consulted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'payment_amount_minor' => 'integer',
            'is_paid' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
