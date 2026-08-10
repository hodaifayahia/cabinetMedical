<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Services\Appointments\AppointmentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
