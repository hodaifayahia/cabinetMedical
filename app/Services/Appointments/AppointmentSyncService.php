<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentSyncService
{
    public function publishUpsert(Appointment $appointment): ?AppointmentSyncEvent
    {
        return $this->publish($appointment, 'upsert');
    }

    public function publishDeletion(Appointment $appointment): ?AppointmentSyncEvent
    {
        return $this->publish($appointment, 'delete');
    }

    /**
     * Requeue an unacknowledged snapshot, or publish a fresh version when the
     * mobile client already acknowledged the current one.
     */
    public function publishOrRetry(Appointment $appointment): ?AppointmentSyncEvent
    {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        return DB::transaction(function () use ($appointment): ?AppointmentSyncEvent {
            $query = Appointment::withoutCabinetScope()
                ->whereKey($appointment->getKey());

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            /** @var Appointment $locked */
            $locked = $query->firstOrFail();
            $latest = AppointmentSyncEvent::withoutCabinetScope()
                ->where('cabinet_id', $locked->cabinet_id)
                ->where('appointment_public_id', $locked->public_id)
                ->orderByDesc('version')
                ->first();
            $currentPayloadSha256 = $this->payloadSha256($locked);

            if ($latest !== null
                && $latest->version === $locked->sync_version
                && hash_equals($latest->payload_sha256, $currentPayloadSha256)
                && $latest->status !== AppointmentSyncEvent::STATUS_ACKNOWLEDGED) {
                $latest->update([
                    'status' => AppointmentSyncEvent::STATUS_PENDING,
                    'attempts' => $latest->attempts + 1,
                    'last_attempted_at' => now(),
                    'last_error' => null,
                ]);

                return $latest->fresh();
            }

            $locked->forceFill([
                'sync_version' => max(1, (int) $locked->sync_version) + 1,
            ])->saveQuietly();

            return $this->publishUpsert($locked->fresh());
        });
    }

    /**
     * Repair changes made through a bulk Eloquent update, which intentionally
     * bypasses model events. This keeps the cursor stream complete without
     * requiring consultation/payment controllers to know about sync internals.
     */
    public function reconcileRecent(int $limit = 500): void
    {
        Appointment::query()
            ->withTrashed()
            ->with('latestSyncEvent')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Appointment $appointment): void {
                $latest = $appointment->latestSyncEvent;

                if ($latest !== null
                    && hash_equals($latest->payload_sha256, $this->payloadSha256($appointment))) {
                    return;
                }

                $this->publishCurrentAsNewVersion($appointment);
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Appointment $appointment): array
    {
        return [
            'public_id' => $appointment->public_id,
            'legacy_id' => (int) $appointment->getKey(),
            'patient_id' => (int) $appointment->patient_id,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'ends_at' => $appointment->ends_at?->toIso8601String(),
            'status' => $appointment->status->value,
            'reason' => $appointment->reason,
            'prestation' => $appointment->prestation,
            'reception_notes' => $appointment->reception_notes,
            'cancellation_reason' => $appointment->cancellation_reason,
            'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            'checked_in_at' => $appointment->checked_in_at?->toIso8601String(),
            'started_at' => $appointment->started_at?->toIso8601String(),
            'completed_at' => $appointment->completed_at?->toIso8601String(),
            'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
            'deleted_at' => $appointment->deleted_at?->toIso8601String(),
            'version' => (int) $appointment->sync_version,
            'created_at' => $appointment->created_at?->toIso8601String(),
            'updated_at' => $appointment->updated_at?->toIso8601String(),
        ];
    }

    public function payloadSha256(Appointment $appointment): string
    {
        return hash('sha256', $this->encodedPayload($appointment));
    }

    private function publish(Appointment $appointment, string $action): ?AppointmentSyncEvent
    {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        $payload = $this->payload($appointment);
        $encodedPayload = $this->encodedPayload($appointment);

        return AppointmentSyncEvent::withoutCabinetScope()->firstOrCreate(
            [
                'cabinet_id' => $appointment->cabinet_id,
                'appointment_public_id' => $appointment->public_id,
                'version' => $appointment->sync_version,
            ],
            [
                'event_id' => (string) Str::uuid7(),
                'appointment_id' => $appointment->getKey(),
                'action' => $action,
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $encodedPayload),
                'status' => AppointmentSyncEvent::STATUS_PENDING,
                'attempts' => 1,
                'last_attempted_at' => now(),
            ],
        );
    }

    private function publishCurrentAsNewVersion(Appointment $appointment): ?AppointmentSyncEvent
    {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        return DB::transaction(function () use ($appointment): ?AppointmentSyncEvent {
            $query = Appointment::withoutCabinetScope()
                ->withTrashed()
                ->where('cabinet_id', $appointment->cabinet_id)
                ->whereKey($appointment->getKey());

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            /** @var Appointment $locked */
            $locked = $query->firstOrFail();
            $latest = AppointmentSyncEvent::withoutCabinetScope()
                ->where('cabinet_id', $locked->cabinet_id)
                ->where('appointment_public_id', $locked->public_id)
                ->orderByDesc('version')
                ->first();

            if ($latest !== null
                && hash_equals($latest->payload_sha256, $this->payloadSha256($locked))) {
                return $latest;
            }

            $latestVersion = $latest === null ? 0 : (int) $latest->version;

            $locked->forceFill([
                'sync_version' => max(
                    1,
                    (int) $locked->sync_version,
                    $latestVersion,
                ) + 1,
            ])->saveQuietly();

            return $locked->trashed()
                ? $this->publishDeletion($locked)
                : $this->publishUpsert($locked);
        });
    }

    private function encodedPayload(Appointment $appointment): string
    {
        return json_encode(
            $this->payload($appointment),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
