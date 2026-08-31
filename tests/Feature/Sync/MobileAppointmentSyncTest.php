<?php

namespace Tests\Feature\Sync;

use App\Models\ApplicationSetting;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\Patient;
use App\Models\SyncState;
use App\Services\Sync\MobileAppointmentSynchroniser;
use App\Services\Sync\MobileSyncSettings;
use App\Services\Sync\SyncReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * End-to-end behaviour of one "synchroniser avec l'application mobile" run.
 *
 * The remote is faked as a real cursor server — it honours the `cursor` query
 * parameter — so resuming, replaying, and acknowledging are exercised the way
 * they behave against the live service rather than against a canned response.
 */
class MobileAppointmentSyncTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private const ENDPOINT = 'https://sync.drclick.test';

    private int $cabinetId;

    /** Events the fake remote will serve, in cursor order. */
    private array $remoteEvents = [];

    /** Envelopes the fake remote received on its push endpoint. */
    private array $pushedEnvelopes = [];

    /** Cursor the fake remote was last asked to acknowledge. */
    private ?int $acknowledgedCursor = null;

    private bool $offline = false;

    private ?int $forcedStatus = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$cabinet, $owner] = $this->activeCabinetWithOwner('sync-run@example.com');
        $this->cabinetId = (int) $cabinet->getKey();
        $this->actingAs($owner);

        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'test-token');
        $this->installFakeRemote();
    }

    /**
     * One stub for the whole suite, driven by this object's state.
     *
     * `Http::fake()` merges stubs rather than replacing them, so registering a
     * second set mid-test would be silently ignored; everything variable lives
     * in instance state instead.
     */
    private function installFakeRemote(): void
    {
        Http::fake(function (Request $request) {
            if ($this->offline) {
                throw new ConnectionException('offline');
            }

            if ($this->forcedStatus !== null) {
                return Http::response(['message' => 'refused'], $this->forcedStatus);
            }

            $url = $request->url();

            if (str_contains($url, '/sync/appointments/push')) {
                $this->pushedEnvelopes = array_merge(
                    $this->pushedEnvelopes,
                    $request['events'] ?? [],
                );

                return Http::response([
                    'applied' => count($request['events'] ?? []),
                    'results' => [],
                ]);
            }

            if (str_contains($url, '/sync/appointments/ack')) {
                $this->acknowledgedCursor = (int) $request['cursor'];

                return Http::response([
                    'acknowledged_cursor' => $this->acknowledgedCursor,
                    'acknowledged_count' => 1,
                ]);
            }

            // The pull stream: everything after the requested cursor.
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $cursor = (int) ($query['cursor'] ?? 0);
            $after = array_values(array_filter(
                $this->remoteEvents,
                static fn (array $event): bool => (int) $event['cursor'] > $cursor,
            ));

            return Http::response([
                'data' => $after,
                'meta' => [
                    'requested_cursor' => $cursor,
                    'next_cursor' => $after === [] ? $cursor : (int) end($after)['cursor'],
                    'has_more' => false,
                ],
            ]);
        });
    }

    private function synchronise(): SyncReport
    {
        return app(MobileAppointmentSynchroniser::class)->synchronise($this->cabinetId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function remoteEvent(int $cursor, array $overrides = []): array
    {
        $payload = array_merge([
            'public_id' => (string) Str::uuid7(),
            'legacy_id' => 900 + $cursor,
            'patient_id' => 555,
            'patient' => [
                'public_id' => (string) Str::uuid7(),
                'patient_number' => null,
                'first_name' => 'Yacine',
                'last_name' => 'Meziane',
                'date_of_birth' => '1985-02-02',
                'gender' => 'male',
                'phone' => '0661778899',
                'email' => null,
            ],
            'appointment_date' => '2026-10-01',
            'starts_at' => '2026-10-01T09:00:00+00:00',
            'ends_at' => '2026-10-01T09:30:00+00:00',
            'status' => 'scheduled',
            'reason' => 'Suivi',
            'prestation' => null,
            'reception_notes' => null,
            'cancellation_reason' => null,
            'confirmed_at' => null,
            'checked_in_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'deleted_at' => null,
            'version' => 1,
            'created_at' => '2026-09-20T08:00:00+00:00',
            'updated_at' => '2026-09-20T08:00:00+00:00',
        ], $overrides);

        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            'cursor' => $cursor,
            'event_id' => (string) Str::uuid7(),
            'appointment_public_id' => $payload['public_id'],
            'version' => $payload['version'],
            'action' => 'upsert',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $encoded),
        ];
    }

    public function test_a_run_imports_remote_appointments_and_acknowledges_them(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];

        $report = $this->synchronise();

        $this->assertFalse($report->failed());
        $this->assertSame(1, $report->created);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(7, $this->acknowledgedCursor);
    }

    public function test_the_pull_cursor_is_stored_so_the_next_run_resumes(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];

        $this->synchronise();

        $state = SyncState::withoutCabinetScope()->sole();
        $this->assertSame(7, $state->pull_cursor);
        $this->assertNotNull($state->last_synced_at);
        $this->assertNull($state->last_error);
    }

    public function test_a_second_run_re_reads_nothing_and_changes_nothing(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];
        $this->synchronise();

        $report = $this->synchronise();

        $this->assertSame(0, $report->pulled, 'The stored cursor must skip events already consumed.');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_replayed_event_is_recognised_rather_than_duplicated(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];
        $this->synchronise();

        // The remote rewinds and serves the same event again.
        SyncState::withoutCabinetScope()->sole()->forceFill(['pull_cursor' => 0])->save();
        $report = $this->synchronise();

        $this->assertSame(1, $report->pulled);
        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_status_change_from_the_mobile_app_updates_the_local_appointment(): void
    {
        $first = $this->remoteEvent(7);
        $this->remoteEvents = [$first];
        $this->synchronise();

        $this->remoteEvents[] = $this->remoteEvent(8, [
            'public_id' => $first['payload']['public_id'],
            'patient' => $first['payload']['patient'],
            'status' => 'confirmed',
            'confirmed_at' => '2026-09-25T10:00:00+00:00',
            'version' => 2,
        ]);

        $report = $this->synchronise();

        $this->assertSame(1, $report->updated);
        $this->assertSame('confirmed', Appointment::query()->sole()->status->value);
        $this->assertSame(2, (int) Appointment::query()->sole()->sync_version);
    }

    public function test_a_remote_cancellation_soft_deletes_the_local_appointment(): void
    {
        $first = $this->remoteEvent(7);
        $this->remoteEvents = [$first];
        $this->synchronise();

        $tombstone = $this->remoteEvent(8, [
            'public_id' => $first['payload']['public_id'],
            'patient' => $first['payload']['patient'],
            'version' => 2,
        ]);
        $tombstone['action'] = 'delete';
        $this->remoteEvents[] = $tombstone;

        $report = $this->synchronise();

        $this->assertSame(1, $report->deleted);
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(1, Appointment::query()->withTrashed()->count());
    }

    public function test_local_appointments_are_pushed_to_the_remote(): void
    {
        $appointment = Appointment::factory()->for(Patient::factory()->create())->create();

        $report = $this->synchronise();

        $this->assertGreaterThan(0, $report->pushed);
        $match = collect($this->pushedEnvelopes)
            ->firstWhere('appointment_public_id', $appointment->public_id);

        $this->assertNotNull($match, 'The local appointment must be delivered upward.');
        // The pushed payload carries the portable patient identity, so the
        // remote can resolve or create the patient on its side.
        $this->assertNotEmpty($match['payload']['patient']['public_id']);
        $this->assertSame(hash(
            'sha256',
            json_encode($match['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ), $match['payload_sha256']);
    }

    public function test_an_imported_appointment_is_not_pushed_straight_back(): void
    {
        AppointmentSyncEvent::withoutCabinetScope()->delete();
        $this->remoteEvents = [$this->remoteEvent(7)];

        $report = $this->synchronise();

        $this->assertSame(1, $report->created);
        $this->assertSame(0, $report->pushed, 'Importing must not queue the same change for push.');
        $this->assertSame([], $this->pushedEnvelopes);
    }

    public function test_an_imported_change_is_still_visible_on_the_local_stream(): void
    {
        AppointmentSyncEvent::withoutCabinetScope()->delete();
        $this->remoteEvents = [$this->remoteEvent(7)];

        $this->synchronise();

        // Recorded, so local history stays complete and reconciliation does not
        // mistake it for an unpublished change — but marked so it is never sent
        // back to the installation it came from.
        $event = AppointmentSyncEvent::withoutCabinetScope()->sole();
        $this->assertSame(AppointmentSyncEvent::STATUS_IMPORTED, $event->status);
    }

    public function test_importing_does_not_bump_the_local_version(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];

        $this->synchronise();

        // A bump here would make the next remote version look stale and the
        // appointment would stop receiving updates.
        $this->assertSame(1, (int) Appointment::query()->sole()->sync_version);
    }

    public function test_the_push_cursor_advances_so_changes_are_sent_once(): void
    {
        Appointment::factory()->for(Patient::factory()->create())->create();

        $first = $this->synchronise();
        $delivered = count($this->pushedEnvelopes);
        $second = $this->synchronise();

        $this->assertGreaterThan(0, $first->pushed);
        $this->assertSame(0, $second->pushed);
        $this->assertCount($delivered, $this->pushedEnvelopes);
    }

    public function test_being_offline_is_reported_without_losing_the_cursor(): void
    {
        $this->remoteEvents = [$this->remoteEvent(7)];
        $this->synchronise();

        $this->offline = true;
        $report = $this->synchronise();

        $this->assertTrue($report->failed());
        $this->assertTrue($report->offline);
        // The cursor survives, so the next connected run resumes rather than
        // replaying the whole history.
        $this->assertSame(7, SyncState::withoutCabinetScope()->sole()->pull_cursor);
    }

    public function test_an_expired_token_is_reported_as_a_real_failure(): void
    {
        $this->forcedStatus = 401;

        $report = $this->synchronise();

        $this->assertTrue($report->failed());
        $this->assertFalse($report->offline);
        $this->assertStringContainsString('autorisation', $report->error);
        $this->assertNotNull(SyncState::withoutCabinetScope()->sole()->last_error);
    }

    public function test_the_stored_error_never_contains_the_token(): void
    {
        $this->forcedStatus = 500;

        $this->synchronise();

        $this->assertStringNotContainsString(
            'test-token',
            (string) SyncState::withoutCabinetScope()->sole()->last_error,
        );
    }

    public function test_a_run_without_configuration_fails_before_touching_the_network(): void
    {
        ApplicationSetting::query()
            ->where('key', MobileSyncSettings::KEY_TOKEN)
            ->delete();

        $report = $this->synchronise();

        $this->assertTrue($report->failed());
        Http::assertNothingSent();
    }

    public function test_a_tampered_remote_event_is_rejected_and_reported(): void
    {
        $event = $this->remoteEvent(7);
        $event['payload']['status'] = 'completed';
        $this->remoteEvents = [$event];

        $report = $this->synchronise();

        $this->assertSame(0, $report->created);
        $this->assertSame(['payload_checksum_mismatch'], $report->rejections);
        $this->assertSame(0, Appointment::query()->count());
    }
}
