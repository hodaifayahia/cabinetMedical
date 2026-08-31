<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Services\Appointments\AppointmentSyncService;
use App\Services\Sync\AppointmentImporter;
use App\Services\Sync\ImportResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AppointmentSyncController extends Controller
{
    /**
     * Return an ordered, resumable stream of appointment changes. The cursor
     * is opaque to clients even though it is currently the event row ID.
     */
    public function index(Request $request, AppointmentSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $cursor = (int) ($validated['cursor'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 50);

        $sync->reconcileRecent();

        $events = AppointmentSyncEvent::query()
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $events->count() > $limit;
        $page = $events->take($limit)->values();
        $nextCursor = (int) ($page->last()?->getKey() ?? $cursor);

        return response()->json([
            'data' => $page->map(static fn (AppointmentSyncEvent $event): array => [
                'cursor' => (int) $event->getKey(),
                'event_id' => $event->event_id,
                'appointment_public_id' => $event->appointment_public_id,
                'version' => (int) $event->version,
                'action' => $event->action,
                'payload' => $event->payload,
                'payload_sha256' => $event->payload_sha256,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'requested_cursor' => $cursor,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Acknowledge every event through a consumed cursor. The cabinet scope on
     * the model prevents a token from acknowledging another tenant's stream.
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'cursor' => ['required', 'integer', 'min:1'],
        ]);
        $cursor = (int) $validated['cursor'];
        $acknowledgedAt = now();
        $count = AppointmentSyncEvent::query()
            ->where('id', '<=', $cursor)
            ->where('status', '!=', AppointmentSyncEvent::STATUS_ACKNOWLEDGED)
            ->update([
                'status' => AppointmentSyncEvent::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => $acknowledgedAt,
                'acknowledged_by' => $request->user()?->getKey(),
                'last_error' => null,
                'updated_at' => $acknowledgedAt,
            ]);

        return response()->json([
            'acknowledged_cursor' => $cursor,
            'acknowledged_count' => $count,
        ]);
    }

    /**
     * Accept appointment changes from another installation.
     *
     * This is the mirror of {@see self::index()}: a local-first desktop sends
     * its own changes here, and they are applied with the same importer the
     * desktop uses in the other direction. One conflict rule therefore governs
     * both directions — a version that is not newer than the stored one is
     * ignored, so a replayed batch is harmless.
     *
     * Accepted changes are republished on this cabinet's event stream so the
     * mobile app sees work done on the desktop. That cannot loop: the
     * republished event carries the same version the desktop already holds, and
     * the desktop's importer ignores anything that is not newer.
     */
    public function push(
        Request $request,
        AppointmentImporter $importer,
        AppointmentSyncService $sync,
    ): JsonResponse {
        $this->authorize('create', Appointment::class);

        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.appointment_public_id' => ['required', 'uuid'],
            'events.*.version' => ['required', 'integer', 'min:1'],
            'events.*.action' => ['required', 'string', 'in:upsert,delete'],
            'events.*.payload' => ['required', 'array'],
            'events.*.payload_sha256' => ['nullable', 'string', 'size:64'],
        ]);

        $cabinetId = $request->user()?->cabinet_id;

        if ($cabinetId === null) {
            return response()->json([
                'message' => "Ce compte n'est rattaché à aucun cabinet.",
            ], 422);
        }

        $outcomes = [];
        $applied = 0;

        foreach ($validated['events'] as $event) {
            // One unusable event must not fail the batch. Without this, a single
            // poison event would discard the results of everything already
            // applied and permanently stall the sender's push cursor on it.
            try {
                $result = $importer->import((int) $cabinetId, $event);

                if ($result->changedData() && $result->appointment !== null) {
                    $applied++;
                    $this->republish($sync, $result);
                }

                $outcomes[] = [
                    'appointment_public_id' => $event['appointment_public_id'],
                    'outcome' => $result->outcome,
                    'reason' => $result->reason,
                ];
            } catch (Throwable $exception) {
                report($exception);

                $outcomes[] = [
                    'appointment_public_id' => $event['appointment_public_id'],
                    'outcome' => ImportResult::OUTCOME_REJECTED,
                    // Deliberately not the exception message: it can carry SQL
                    // and clinical values, and this response crosses the network.
                    'reason' => 'import_failed',
                ];
            }
        }

        return response()->json([
            'applied' => $applied,
            'results' => $outcomes,
        ]);
    }

    /**
     * Put an accepted change on this cabinet's stream so every other client
     * (notably the mobile app) observes it.
     */
    private function republish(AppointmentSyncService $sync, ImportResult $result): void
    {
        $appointment = $result->appointment;

        if ($appointment === null) {
            return;
        }

        if ($result->outcome === ImportResult::OUTCOME_DELETED) {
            $sync->publishDeletion($appointment);

            return;
        }

        $sync->publishUpsert($appointment);
    }
}
