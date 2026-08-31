<?php

namespace App\Services\Sync;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\Appointments\AppointmentSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies one appointment change published by another installation to this
 * database.
 *
 * Three properties matter here:
 *
 * - **Idempotent.** Re-importing an event that has already been applied is a
 *   no-op, so an interrupted run can safely be repeated from an older cursor.
 * - **Silent.** Imported writes use `saveQuietly()` and deliberately publish no
 *   local sync event. Without this, importing a change would queue that same
 *   change for push, the remote would accept it as a new version, and the two
 *   installations would bounce one appointment back and forth forever.
 * - **Version-ordered.** A local record at the same or a higher version is left
 *   alone; the push phase carries the local state upward instead.
 */
final class AppointmentImporter
{
    /** Fields compared verbatim when detecting a same-version divergence. */
    private const CLINICAL_FIELDS = [
        'appointment_date',
        'status',
        'reason',
        'prestation',
        'reception_notes',
        'cancellation_reason',
    ];

    /** Fields normalised to UTC before comparison. */
    private const CLINICAL_INSTANT_FIELDS = [
        'starts_at',
        'ends_at',
        'confirmed_at',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'deleted_at',
    ];

    public function __construct(
        private readonly PatientResolver $patients,
        private readonly AppointmentSyncService $events,
    ) {}

    /**
     * Apply one event from the remote stream.
     *
     * @param  array<string, mixed>  $event  One element of the `data` array of
     *                                       `GET /api/v1/sync/appointments`.
     */
    public function import(int $cabinetId, array $event): ImportResult
    {
        if ($this->actorCabinetConflicts($cabinetId)) {
            return ImportResult::rejected('cabinet_mismatch');
        }

        $publicId = $event['appointment_public_id'] ?? null;
        $payload = $event['payload'] ?? null;

        if (! is_string($publicId) || $publicId === '' || ! is_array($payload)) {
            return ImportResult::rejected('malformed_event');
        }

        if (! $this->payloadIsIntact($payload, $event['payload_sha256'] ?? null)) {
            return ImportResult::rejected('payload_checksum_mismatch');
        }

        $version = (int) ($event['version'] ?? 0);
        $action = (string) ($event['action'] ?? 'upsert');

        return DB::transaction(function () use ($cabinetId, $publicId, $payload, $version, $action): ImportResult {
            $local = Appointment::withoutCabinetScope()
                ->withTrashed()
                ->where('cabinet_id', $cabinetId)
                ->where('public_id', $publicId)
                ->first();

            if ($local instanceof Appointment && (int) $local->sync_version >= $version) {
                // Equal versions with different content mean both sides edited
                // the same appointment independently and landed on the same
                // number. Local state is kept — an import must never silently
                // overwrite a clinician's own edit — but the divergence is
                // reported rather than disappearing, because the two records
                // will not converge on their own.
                if ((int) $local->sync_version === $version
                    && ! hash_equals(
                        $this->clinicalFingerprint($payload),
                        $this->clinicalFingerprint($this->events->payload($local)),
                    )) {
                    return ImportResult::skipped('version_conflict');
                }

                // Already applied, or this installation holds a newer version
                // that the push phase will carry upward.
                return ImportResult::skipped('not_newer');
            }

            if ($action === 'delete') {
                return $local instanceof Appointment
                    ? $this->applyDeletion($local, $version)
                    // A tombstone for an appointment this installation never
                    // saw needs no local representation.
                    : ImportResult::skipped('unknown_tombstone');
            }

            $patient = $this->patients->resolve($cabinetId, $payload['patient'] ?? null);

            if (! $patient instanceof Patient) {
                return ImportResult::rejected('patient_unresolvable');
            }

            return $local instanceof Appointment
                ? $this->applyUpdate($local, $patient, $payload, $version)
                : $this->applyCreation($cabinetId, $publicId, $patient, $payload, $version);
        });
    }

    /**
     * A checksum over only the clinically meaningful fields, comparable across
     * installations.
     *
     * The full payload checksum is not usable here: it covers `legacy_id` and
     * `patient_id`, which are each installation's own auto-increment keys, and
     * it renders timestamps in the local timezone. Two identical appointments
     * therefore hash differently on two machines, and every replay would look
     * like a conflict. Datetimes are normalised to UTC before hashing so the
     * same instant compares equal wherever it was written.
     *
     * @param  array<string, mixed>  $payload
     */
    private function clinicalFingerprint(array $payload): string
    {
        $fingerprint = [];

        foreach (self::CLINICAL_FIELDS as $field) {
            $value = $payload[$field] ?? null;
            $fingerprint[$field] = is_string($value) ? $value : null;
        }

        foreach (self::CLINICAL_INSTANT_FIELDS as $field) {
            $value = $payload[$field] ?? null;
            $fingerprint[$field] = is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)->utc()->toIso8601String()
                : null;
        }

        return hash('sha256', json_encode(
            $fingerprint,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Write the imported change onto this cabinet's own event stream.
     *
     * Two things depend on it. `reconcileRecent()` would otherwise see an
     * appointment with no event and republish it as a fresh local version,
     * which is exactly the echo this importer exists to avoid. And other
     * clients of this cabinet still need to observe the change on the cursor
     * stream. The row is marked `imported`, so the push phase skips it.
     */
    private function recordImported(Appointment $appointment): void
    {
        $fresh = $appointment->fresh();

        if (! $fresh instanceof Appointment) {
            return;
        }

        $this->events->recordImported(
            $fresh,
            $fresh->trashed() ? 'delete' : 'upsert',
        );
    }

    /**
     * Refuse to import into a cabinet other than the acting user's.
     *
     * `BelongsToCabinet` reassigns `cabinet_id` on create from the
     * authenticated user, which would silently override the cabinet this run
     * targets. In production the two always agree, but a mismatch would write
     * one clinic's appointments into another's records, so it fails loudly
     * instead of being quietly corrected.
     */
    private function actorCabinetConflicts(int $cabinetId): bool
    {
        $user = auth()->user();

        if ($user === null || $user->is_platform_admin === true) {
            return false;
        }

        return $user->cabinet_id !== null && (int) $user->cabinet_id !== $cabinetId;
    }

    private function applyCreation(
        int $cabinetId,
        string $publicId,
        Patient $patient,
        array $payload,
        int $version,
    ): ImportResult {
        $appointment = new Appointment;
        $appointment->forceFill([
            'cabinet_id' => $cabinetId,
            'public_id' => $publicId,
            // The local primary key of the resolved patient, never the remote
            // installation's `patient_id`.
            'patient_id' => $patient->getKey(),
            'sync_version' => $version,
        ] + $this->mutableAttributes($payload));

        // Quietly: an import must not publish an event that would be pushed
        // straight back to the installation it came from.
        $appointment->saveQuietly();

        if ($this->deletedAt($payload) !== null) {
            $this->softDeleteQuietly($appointment, $this->deletedAt($payload));
        }

        $this->recordImported($appointment);

        return ImportResult::created($appointment);
    }

    private function applyUpdate(
        Appointment $appointment,
        Patient $patient,
        array $payload,
        int $version,
    ): ImportResult {
        $appointment->forceFill([
            'patient_id' => $patient->getKey(),
            'sync_version' => $version,
        ] + $this->mutableAttributes($payload));
        $appointment->saveQuietly();

        $deletedAt = $this->deletedAt($payload);

        if ($deletedAt !== null && ! $appointment->trashed()) {
            $this->softDeleteQuietly($appointment, $deletedAt);
        }

        if ($deletedAt === null && $appointment->trashed()) {
            // The remote revived it; mirror that rather than leaving a local
            // tombstone the clinic cannot see.
            Appointment::withoutCabinetScope()
                ->withTrashed()
                ->whereKey($appointment->getKey())
                ->update(['deleted_at' => null]);
        }

        $this->recordImported($appointment);

        return ImportResult::updated($appointment);
    }

    private function applyDeletion(Appointment $appointment, int $version): ImportResult
    {
        if ($appointment->trashed()) {
            return ImportResult::skipped('already_deleted');
        }

        $appointment->forceFill(['sync_version' => $version])->saveQuietly();
        $this->softDeleteQuietly($appointment, now()->toIso8601String());

        $this->recordImported($appointment);

        return ImportResult::deleted($appointment);
    }

    /**
     * Soft-delete without firing the `deleting`/`deleted` hooks, which would
     * publish a local tombstone event and start the echo loop.
     */
    private function softDeleteQuietly(Appointment $appointment, string $deletedAt): void
    {
        Appointment::withoutCabinetScope()
            ->withTrashed()
            ->whereKey($appointment->getKey())
            ->update(['deleted_at' => $this->instant($deletedAt) ?? now()]);
    }

    /**
     * The appointment columns an import is allowed to overwrite.
     *
     * `cabinet_id`, `public_id`, `patient_id`, and the mobile idempotency
     * columns are set explicitly by the caller and are never taken from the
     * payload, so a malformed event cannot move an appointment between tenants
     * or onto another patient's file.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mutableAttributes(array $payload): array
    {
        $startsAt = $this->instant($payload['starts_at'] ?? null);

        return [
            // Kept consistent with the localised start, so day filtering agrees
            // with the time shown on the appointment.
            'appointment_date' => $startsAt?->toDateString()
                ?? ($payload['appointment_date'] ?? null),
            'starts_at' => $startsAt,
            'ends_at' => $this->instant($payload['ends_at'] ?? null),
            'status' => $this->status($payload['status'] ?? null),
            'reason' => $payload['reason'] ?? null,
            'prestation' => $payload['prestation'] ?? null,
            'reception_notes' => $payload['reception_notes'] ?? null,
            'cancellation_reason' => $payload['cancellation_reason'] ?? null,
            'confirmed_at' => $this->instant($payload['confirmed_at'] ?? null),
            'checked_in_at' => $this->instant($payload['checked_in_at'] ?? null),
            'started_at' => $this->instant($payload['started_at'] ?? null),
            'completed_at' => $this->instant($payload['completed_at'] ?? null),
            'cancelled_at' => $this->instant($payload['cancelled_at'] ?? null),
        ];
    }

    /**
     * Convert an incoming timestamp to the application timezone.
     *
     * The remote publishes ISO-8601 with an explicit offset. Assigning that
     * string straight to a datetime cast stores its wall-clock digits and
     * re-reads them as local time, which silently shifts every synced
     * appointment by the UTC offset — an hour wrong on a patient's record.
     * Converting first keeps the instant intact.
     */
    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * An unrecognised status from a newer client falls back to `scheduled`
     * rather than failing the run on an enum cast.
     */
    private function status(mixed $value): AppointmentStatus
    {
        return is_string($value)
            ? (AppointmentStatus::tryFrom($value) ?? AppointmentStatus::SCHEDULED)
            : AppointmentStatus::SCHEDULED;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deletedAt(array $payload): ?string
    {
        $deletedAt = $payload['deleted_at'] ?? null;

        return is_string($deletedAt) && $deletedAt !== '' ? $deletedAt : null;
    }

    /**
     * Confirm the payload is byte-for-byte what the publisher hashed.
     *
     * Both sides run this codebase and encode with the same flags, so a
     * mismatch means the event was altered or truncated in transit. Importing
     * altered clinical data is worse than refusing the event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadIsIntact(array $payload, mixed $expectedSha256): bool
    {
        if (! is_string($expectedSha256) || $expectedSha256 === '') {
            // Older publishers did not send a checksum; nothing to verify.
            return true;
        }

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            return false;
        }

        return hash_equals($expectedSha256, hash('sha256', $encoded));
    }
}
