<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $patient_id
 * @property CarbonImmutable|null $consulted_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $payment_amount_minor
 * @property int $payment_adjustment_minor
 * @property bool $is_paid
 */
#[Fillable([
    'cabinet_id',
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
    'payment_adjustment_minor',
    'payment_method',
    'payment_service',
    'payment_notes',
    'is_paid',
    'payment_settled_at',
    'status',
    'completed_at',
    'created_by',
])]
class Consultation extends Model
{
    use BelongsToCabinet;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consulted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'payment_amount_minor' => 'integer',
            'payment_adjustment_minor' => 'integer',
            'is_paid' => 'boolean',
            'payment_settled_at' => 'immutable_datetime',
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

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at');
    }

    public function collectedMinor(): int
    {
        if (array_key_exists('payments_sum_amount_minor', $this->getAttributes())) {
            $sum = (int) ($this->getAttribute('payments_sum_amount_minor') ?? 0);
        } elseif ($this->relationLoaded('payments')) {
            $sum = (int) $this->payments->sum('amount_minor');
        } else {
            $sum = (int) $this->payments()->sum('amount_minor');
        }

        // Compatibility for records imported/created outside the application
        // after the ledger migration.
        if ($sum === 0 && $this->is_paid && (int) $this->payment_adjustment_minor === 0) {
            return (int) ($this->payment_amount_minor ?? 0);
        }

        return $sum;
    }

    public function outstandingMinor(): int
    {
        return max(0, (int) ($this->payment_amount_minor ?? 0)
            - $this->collectedMinor()
            - (int) ($this->payment_adjustment_minor ?? 0));
    }

    public function paymentStatus(): string
    {
        if ($this->is_paid || $this->outstandingMinor() === 0) {
            return 'paid';
        }

        return $this->collectedMinor() > 0 ? 'partial' : 'unpaid';
    }
}
