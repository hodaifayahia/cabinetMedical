<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AppointmentStatus $status
 * @property CarbonImmutable|null $appointment_date
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'patient_id',
    'appointment_date',
    'starts_at',
    'ends_at',
    'status',
    'reason',
    'prestation',
    'reception_notes',
    'created_by',
    'cancelled_by',
    'cancellation_reason',
    'confirmed_at',
    'checked_in_at',
    'started_at',
    'completed_at',
    'cancelled_at',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $appointment): void {
            if ($appointment->starts_at !== null) {
                $appointment->setAttribute(
                    'appointment_date',
                    $appointment->starts_at->toDateString(),
                );
            }

            if ($appointment->getAttribute('status') === null) {
                $appointment->setAttribute('status', AppointmentStatus::SCHEDULED);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        // Keep historical appointments readable when a patient dossier is archived.
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::blockingValues());
    }
}
