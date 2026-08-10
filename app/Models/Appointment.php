<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToCabinet;
use App\Services\Appointments\AppointmentSyncService;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property AppointmentStatus $status
 * @property int|null $cabinet_id
 * @property string|null $public_id
 * @property int $sync_version
 * @property CarbonImmutable|null $appointment_date
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read AppointmentSyncEvent|null $latestSyncEvent
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
    'mobile_idempotency_key_hash',
    'mobile_idempotency_fingerprint',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToCabinet, HasFactory, SoftDeletes;

    /** @var list<string> */
    private const SYNC_TRACKED_COLUMNS = [
        'patient_id',
        'appointment_date',
        'starts_at',
        'ends_at',
        'status',
        'reason',
        'prestation',
        'reception_notes',
        'cancelled_by',
        'cancellation_reason',
        'confirmed_at',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'deleted_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $appointment): void {
            if ($appointment->getAttribute('public_id') === null) {
                $appointment->setAttribute('public_id', (string) Str::uuid7());
            }

            if ($appointment->getAttribute('sync_version') === null) {
                $appointment->setAttribute('sync_version', 1);
            }
        });

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

        static::updating(function (self $appointment): void {
            if ($appointment->isDirty(self::SYNC_TRACKED_COLUMNS)) {
                $appointment->setAttribute(
                    'sync_version',
                    max(1, (int) $appointment->getOriginal('sync_version')) + 1,
                );
            }
        });

        static::created(static function (self $appointment): void {
            app(AppointmentSyncService::class)->publishUpsert($appointment);
        });

        static::updated(static function (self $appointment): void {
            if ($appointment->wasChanged('sync_version')) {
                app(AppointmentSyncService::class)->publishUpsert($appointment);
            }
        });

        static::deleting(static function (self $appointment): void {
            if ($appointment->isForceDeleting()) {
                return;
            }

            $nextVersion = max(1, (int) $appointment->sync_version) + 1;

            self::withoutCabinetScope()
                ->whereKey($appointment->getKey())
                ->update(['sync_version' => $nextVersion]);

            $appointment->setAttribute('sync_version', $nextVersion);
        });

        static::deleted(static function (self $appointment): void {
            if (! $appointment->isForceDeleting()) {
                app(AppointmentSyncService::class)->publishDeletion($appointment);
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
            'sync_version' => 'integer',
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

    /** @return HasMany<AppointmentSyncEvent, $this> */
    public function syncEvents(): HasMany
    {
        return $this->hasMany(AppointmentSyncEvent::class);
    }

    /** @return HasOne<AppointmentSyncEvent, $this> */
    public function latestSyncEvent(): HasOne
    {
        return $this->hasOne(AppointmentSyncEvent::class)
            ->latestOfMany('version')
            ->select([
                'appointment_sync_events.id',
                'appointment_sync_events.appointment_id',
                'appointment_sync_events.version',
                'appointment_sync_events.status',
                'appointment_sync_events.payload_sha256',
                'appointment_sync_events.last_attempted_at',
                'appointment_sync_events.acknowledged_at',
            ]);
    }

    /**
     * Keep numeric route keys backward compatible while also allowing mobile
     * clients to address an appointment by its durable public identifier.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field === null && is_string($value) && Str::isUuid($value)) {
            $field = 'public_id';
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::blockingValues());
    }
}
