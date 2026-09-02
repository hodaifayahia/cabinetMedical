<?php

namespace App\Services\Sync;

use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\SyncState;
use App\Services\Appointments\AppointmentSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one two-way appointment sync between this installation and the remote
 * service the mobile application uses.
 *
 * Pull, then push, both resumable from `sync_states`:
 *
 *  - **Pull** reads the remote event stream from the stored cursor and applies
 *    each event through {@see AppointmentImporter}, which resolves the patient
 *    against this database and keeps the local primary key.
 *  - **Push** sends local changes upward. The remote applies them with the same
 *    importer, so both directions share one conflict rule and one echo guard.
 *
 * A run is safe to repeat. Cursors advance only after the work they cover has
 * been applied, so an interruption re-does at most one page.
 */
final class MobileAppointmentSynchroniser
{
    /** Remote events fetched per request. */
    private const PULL_PAGE_SIZE = 100;

    /** Local appointments delivered per request. */
    private const PUSH_BATCH_SIZE = 100;

    /**
     * A bound on one run so a large backlog cannot hold the UI open
     * indefinitely. The next run continues from the stored cursor.
     */
    private const MAX_PAGES_PER_RUN = 50;

    public function __construct(
        private readonly MobileSyncClient $client,
        private readonly AppointmentImporter $importer,
        private readonly AppointmentSyncService $sync,
        private readonly MobileSyncSettings $settings,
    ) {}

    public function synchronise(int $cabinetId): SyncReport
    {
        $report = new SyncReport;
        $endpoint = $this->settings->endpoint();

        if ($endpoint === null || ! $this->settings->isConfigured()) {
            $report->error = "La synchronisation n'est pas configurée sur ce poste.";

            return $report;
        }

        $state = SyncState::forEndpoint($cabinetId, $endpoint, SyncState::STREAM_APPOINTMENTS);

        try {
            $this->pull($cabinetId, $state, $report);
            $this->push($cabinetId, $state, $report);
            $state->markSynced();
        } catch (SyncTransportException $exception) {
            $report->offline = $exception->offline;
            $report->error = $exception->getMessage();
            $state->markFailed($exception->getMessage());
        } catch (Throwable $exception) {
            // Anything else — a database error mid-import, a malformed remote
            // response — must still leave the cursor recorded and the failure
            // visible, rather than escaping and 500-ing the page the clinician
            // pressed the button on.
            report($exception);
            $report->error = "La synchronisation s'est interrompue. Réessayez ; "
                .'les échanges déjà effectués sont conservés.';
            // The exception text can carry SQL and clinical values, so only a
            // stable code is persisted.
            $state->markFailed('sync_run_failed: '.$exception::class);
        }

        return $report;
    }

    /**
     * Import remote changes, advancing the cursor one page at a time.
     */
    private function pull(int $cabinetId, SyncState $state, SyncReport $report): void
    {
        $pages = 0;

        do {
            $page = $this->client->pull($state->pull_cursor, self::PULL_PAGE_SIZE);
            $events = $page['data'];

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $report->pulled++;
                $result = $this->importer->import($cabinetId, $event);
                $report->record($result);

                if ($result->wasRejected()) {
                    // Reasons are non-clinical codes, safe to log.
                    Log::warning('Appointment sync rejected a remote event.', [
                        'cabinet_id' => $cabinetId,
                        'reason' => $result->reason,
                        'event_id' => $event['event_id'] ?? null,
                    ]);
                }
            }

            $nextCursor = (int) ($page['meta']['next_cursor'] ?? $state->pull_cursor);

            // Only advance once the whole page has been applied, so an
            // interruption replays that page instead of skipping it.
            if ($nextCursor > $state->pull_cursor) {
                $state->forceFill(['pull_cursor' => $nextCursor])->save();
                $this->client->acknowledge($nextCursor);
            }

            $hasMore = (bool) ($page['meta']['has_more'] ?? false);
            $pages++;
        } while ($hasMore && $events !== [] && $pages < self::MAX_PAGES_PER_RUN);
    }

    /**
     * Deliver local changes upward.
     *
     * The stored event payload is not reused. Each appointment's *current*
     * state is re-encoded instead, which keeps a payload written by an older
     * release from being pushed in a shape the remote can no longer read, and
     * collapses a burst of edits into a single delivery.
     */
    private function push(int $cabinetId, SyncState $state, SyncReport $report): void
    {
        // Repair changes made by bulk updates, which bypass model events and so
        // never produced an event row of their own.
        $this->sync->reconcileRecent();

        $pages = 0;

        do {
            $events = AppointmentSyncEvent::withoutCabinetScope()
                ->where('cabinet_id', $cabinetId)
                ->where('id', '>', $state->push_cursor)
                ->orderBy('id')
                ->limit(self::PUSH_BATCH_SIZE)
                ->get();

            if ($events->isEmpty()) {
                return;
            }

            $highestCursor = (int) $events->last()->getKey();

            // Changes that arrived from the remote are already known there.
            // The cursor still advances past them, so they are considered once
            // and never again.
            $outgoing = $events->reject(
                static fn (AppointmentSyncEvent $event): bool => $event->status === AppointmentSyncEvent::STATUS_IMPORTED,
            );
            $envelopes = $this->envelopesFor($cabinetId, $outgoing);

            if ($envelopes !== []) {
                $this->client->push($envelopes);
                $report->pushed += count($envelopes);
            }

            $state->forceFill(['push_cursor' => $highestCursor])->save();
            $pages++;
        } while ($events->count() === self::PUSH_BATCH_SIZE && $pages < self::MAX_PAGES_PER_RUN);
    }

    /**
     * Build one current-state envelope per appointment in the batch.
     *
     * @param  Collection<int, AppointmentSyncEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function envelopesFor(int $cabinetId, $events): array
    {
        $publicIds = $events
            ->pluck('appointment_public_id')
            ->unique()
            ->values()
            ->all();

        $appointments = Appointment::withoutCabinetScope()
            ->withTrashed()
            // The payload's booking block resolves these; without them a
            // batch of 100 mobile appointments would issue three lazy loads
            // apiece, on every page of every push.
            ->with(['patient', 'bookedBy.patientProfile', 'familyMember'])
            ->where('cabinet_id', $cabinetId)
            ->whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');

        $envelopes = [];

        foreach ($publicIds as $publicId) {
            $appointment = $appointments->get($publicId);

            if (! $appointment instanceof Appointment) {
                // Hard-deleted locally; there is no current state to send.
                continue;
            }

            $envelopes[] = [
                'appointment_public_id' => $publicId,
                'version' => (int) $appointment->sync_version,
                'action' => $appointment->trashed() ? 'delete' : 'upsert',
                'payload' => $this->sync->payload($appointment),
                'payload_sha256' => $this->sync->payloadSha256($appointment),
            ];
        }

        return $envelopes;
    }
}
