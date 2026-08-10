<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $event_id
 * @property int $cabinet_id
 * @property int|null $appointment_id
 * @property string $appointment_public_id
 * @property int $version
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string $payload_sha256
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempted_at
 * @property CarbonImmutable|null $acknowledged_at
 */
#[Fillable([
    'event_id',
    'cabinet_id',
    'appointment_id',
    'appointment_public_id',
    'version',
    'action',
    'payload',
    'payload_sha256',
    'status',
    'attempts',
    'last_attempted_at',
    'last_error',
    'acknowledged_at',
    'acknowledged_by',
])]
class AppointmentSyncEvent extends Model
{
    use BelongsToCabinet;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
