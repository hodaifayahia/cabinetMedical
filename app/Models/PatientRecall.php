<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * « Revoir ce patient vers telle date, pour telle raison. »
 *
 * @property int $patient_id
 * @property CarbonImmutable $due_on
 * @property string $reason
 * @property string $status pending|done|cancelled
 * @property CarbonImmutable|null $contacted_at
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable([
    'cabinet_id',
    'public_id',
    'patient_id',
    'consultation_id',
    'due_on',
    'reason',
    'status',
    'contacted_at',
    'completed_at',
    'created_by',
])]
class PatientRecall extends Model
{
    use BelongsToCabinet;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (self $recall): void {
            $recall->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'contacted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }
}
