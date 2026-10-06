<?php

namespace Tests\Feature\Sync;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\SyncState;
use App\Models\User;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Once a desktop is linked, mobile bookings reach it and its own changes
 * reach the mobile app on their own, every two minutes; being offline is
 * silent and retried.
 */
class ScheduledMobileSyncTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private const ENDPOINT = 'https://cloud.drclick.test';

    private User $owner;

    /** @var list<array<string, mixed>> */
    private array $remoteEvents = [];

    /** @var list<array<string, mixed>> */
    private array $pushed = [];

    private ?int $acknowledged = null;

    private bool $offline = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['medismart.online_service.linkable' => true]);
        [$cabinet, $this->owner] = $this->activeCabinetWithOwner('linked-owner@example.com');

        Http::fake(function (Request $request) {
            if ($this->offline) {
                throw new ConnectionException('offline');
            }

            $url = $request->url();

            if (str_contains($url, '/sync/appointments/push')) {
                $this->pushed = array_merge($this->pushed, $request['events'] ?? []);

                return Http::response(['applied' => count($request['events'] ?? []), 'results' => []]);
            }

            if (str_contains($url, '/sync/appointments/ack')) {
                $this->acknowledged = (int) $request['cursor'];

                return Http::response(['acknowledged_cursor' => $this->acknowledged]);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $cursor = (int) ($query['cursor'] ?? 0);
            $after = array_values(array_filter($this->remoteEvents, static fn (array $event): bool => $event['cursor'] > $cursor));

            return Http::response([
                'data' => $after,
                'meta' => [
                    'next_cursor' => $after === [] ? $cursor : (int) end($after)['cursor'],
                    'has_more' => false,
                ],
            ]);
        });
    }

    private function link(): void
    {
        app(MobileSyncSettings::class)->configure(
            self::ENDPOINT,
            'link-token',
            'linked-owner@example.com',
            'Cabinet en ligne',
            (int) $this->owner->cabinet_id,
        );
    }

    /**
     * A booking made in the mobile application, as the online service streams it.
     *
     * @return array<string, mixed>
     */
    private function mobileBooking(int $cursor): array
    {
        $payload = [
            'public_id' => (string) Str::uuid7(),
            'legacy_id' => 900 + $cursor,
            'patient_id' => 555,
            'patient' => [
                'public_id' => (string) Str::uuid7(),
                'patient_number' => null,
                'first_name' => 'Nadia',
                'last_name' => 'Boudiaf',
                'date_of_birth' => '1990-04-12',
                'gender' => 'female',
                'phone' => '0661223344',
                'email' => null,
            ],
            'appointment_date' => '2026-10-08',
            'starts_at' => '2026-10-08T10:00:00+00:00',
            'ends_at' => '2026-10-08T10:30:00+00:00',
            'status' => 'scheduled',
            'reason' => 'Consultation',
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
            'created_at' => '2026-10-06T08:00:00+00:00',
            'updated_at' => '2026-10-06T08:00:00+00:00',
        ];

        return [
            'cursor' => $cursor,
            'event_id' => (string) Str::uuid7(),
            'appointment_public_id' => $payload['public_id'],
            'version' => 1,
            'action' => 'upsert',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    public function test_the_sync_is_scheduled_every_two_minutes_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'drclick:sync-appointments --scheduled'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/2 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_an_unlinked_poste_does_nothing_and_does_not_fail(): void
    {
        $this->artisan('drclick:sync-appointments', ['--scheduled' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_mobile_bookings_reach_the_desktop_and_desktop_changes_go_back(): void
    {
        $this->link();
        $booking = $this->mobileBooking(11);
        $this->remoteEvents = [$booking];

        // Pull: the mobile booking becomes a local appointment and patient.
        $this->artisan('drclick:sync-appointments', ['--scheduled' => true])->assertSuccessful();

        $appointment = Appointment::withoutCabinetScope()->where('public_id', $booking['appointment_public_id'])->sole();
        $this->assertSame(AppointmentStatus::SCHEDULED, $appointment->status);
        $this->assertSame('Boudiaf', Patient::withoutCabinetScope()->findOrFail($appointment->patient_id)->last_name);
        $this->assertSame(11, $this->acknowledged);
        $this->assertSame([], collect($this->pushed)->where('appointment_public_id', $booking['appointment_public_id'])->all(), 'An imported booking is not echoed back.');

        // Push: the reception confirms it on the desktop.
        $this->actingAs($this->owner);
        $appointment->forceFill(['status' => AppointmentStatus::CONFIRMED, 'confirmed_at' => now()])->save();
        $this->artisan('drclick:sync-appointments', ['--scheduled' => true])->assertSuccessful();

        $sent = collect($this->pushed)->firstWhere('appointment_public_id', $booking['appointment_public_id']);
        $this->assertNotNull($sent, 'The desktop change must reach the online service.');
        $this->assertSame('confirmed', $sent['payload']['status']);

        $state = SyncState::withoutCabinetScope()->sole();
        $this->assertNotNull($state->last_synced_at);
        $this->assertFalse($state->last_failure_offline);
    }

    public function test_being_offline_is_silent_retried_and_shown_on_the_service_page(): void
    {
        $this->link();
        $this->offline = true;

        $this->artisan('drclick:sync-appointments', ['--scheduled' => true])->assertSuccessful();

        $state = SyncState::withoutCabinetScope()->sole();
        $this->assertTrue($state->last_failure_offline);

        $this->actingAs($this->owner)
            ->get(route('app.configuration.online-service.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('link.linked', true)
                ->where('sync.offline', true)
                ->where('sync.error', null)
                ->where('canSyncNow', true));

        // Back online: the next run catches up and clears the state.
        $this->offline = false;
        $this->remoteEvents = [$this->mobileBooking(3)];
        $this->artisan('drclick:sync-appointments', ['--scheduled' => true])->assertSuccessful();

        $this->assertSame(1, Appointment::withoutCabinetScope()->count());
        $this->actingAs($this->owner)
            ->get(route('app.configuration.online-service.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sync.offline', false)
                ->whereNot('sync.lastSyncedAt', null));
    }

    public function test_a_manual_run_still_reports_offline_as_a_failure(): void
    {
        $this->link();
        $this->offline = true;

        $this->artisan('drclick:sync-appointments')->assertFailed();
    }
}
