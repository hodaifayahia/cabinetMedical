<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An append-only collection entry allocated to one consultation charge.
 * Corrections are recorded as a new entry/audit event; existing entries are
 * never overwritten by the payment screens.
 *
 * @property int $amount_minor
 * @property CarbonImmutable|null $received_at
 */
#[Fillable([
    'cabinet_id',
    'public_id',
    'consultation_id',
    'patient_id',
    'amount_minor',
    'method',
    'notes',
    'received_at',
    'received_by',
    'client_reference',
])]
class Payment extends Model
{
    use BelongsToCabinet;

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
