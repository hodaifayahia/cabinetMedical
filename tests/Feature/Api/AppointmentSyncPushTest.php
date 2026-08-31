<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * `POST /api/v1/sync/appointments/push` — the ingest side of two-way sync.
 *
 * A local-first desktop delivers its own appointment changes here. They are
 * applied with the same importer used for the pull direction and republished on
 * the cabinet's stream so the mobile app sees them.
 */
class AppointmentSyncPushTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides = []): array
    {
        $payload = array_merge([
            'public_id' => (string) Str::uuid7(),
            'legacy_id' => 12,
            'patient_id' => 88,
            'patient' => [
                'public_id' => (string) Str::uuid7(),
                'patient_number' => null,
                'first_name' => 'Nadia',
                'last_name' => 'Cherif',
                'date_of_birth' => '1992-06-06',
                'gender' => 'female',
                'phone' => '0770112233',
                'email' => null,
            ],
            'appointment_date' => '2026-11-03',
            'starts_at' => '2026-11-03T10:00:00+00:00',
            'ends_at' => '2026-11-03T10:30:00+00:00',
            'status' => 'confirmed',
            'reason' => 'Controle',
            'prestation' => null,
            'reception_notes' => null,
            'cancellation_reason' => null,
            'confirmed_at' => '2026-11-01T09:00:00+00:00',
            'checked_in_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'deleted_at' => null,
            'version' => 3,
            'created_at' => '2026-10-30T08:00:00+00:00',
            'updated_at' => '2026-11-01T09:00:00+00:00',
        ], $overrides);

        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            'appointment_public_id' => $payload['public_id'],
            'version' => $payload['version'],
            'action' => 'upsert',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $encoded),
        ];
    }

    public function test_a_pushed_appointment_is_created_and_republished_for_the_mobile_app(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('push@example.com');
        Sanctum::actingAs($owner);
        $envelope = $this->envelope();

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$envelope]])
            ->assertOk()
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('results.0.outcome', 'created');

        $appointment = Appointment::query()->sole();
        $this->assertSame($envelope['appointment_public_id'], $appointment->public_id);
        $this->assertSame((int) $cabinet->getKey(), (int) $appointment->cabinet_id);

        // Republished so the mobile app observes desktop-side work.
        $event = AppointmentSyncEvent::query()
            ->where('appointment_public_id', $appointment->public_id)
            ->sole();
        $this->assertSame(3, (int) $event->version);
        $this->assertSame('upsert', $event->action);
    }

    public function test_the_republished_event_carries_the_pushed_version_so_it_cannot_loop(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('loop@example.com');
        Sanctum::actingAs($owner);
        $envelope = $this->envelope();

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$envelope]])->assertOk();

        // The desktop already holds version 3, so when it pulls this event back
        // its importer skips it and the exchange terminates.
        $this->assertSame(3, (int) Appointment::query()->sole()->sync_version);
        $this->assertSame(
            1,
            AppointmentSyncEvent::query()->count(),
            'One accepted push must produce exactly one outgoing event.',
        );
    }

    public function test_replaying_a_batch_is_harmless(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('replay@example.com');
        Sanctum::actingAs($owner);
        $envelope = $this->envelope();

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$envelope]])->assertOk();
        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$envelope]])
            ->assertOk()
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('results.0.outcome', 'skipped');

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_pushed_appointment_attaches_to_the_patient_already_on_the_server(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('match@example.com');
        Sanctum::actingAs($owner);
        $existing = Patient::factory()->create([
            'first_name' => 'Nadia',
            'last_name' => 'Cherif',
            'phone' => '0770112233',
        ]);

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$this->envelope()]])
            ->assertOk();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame($existing->getKey(), (int) Appointment::query()->sole()->patient_id);
    }

    public function test_a_push_never_lands_in_another_cabinet(): void
    {
        [$cabinetA, $ownerA] = $this->activeCabinetWithOwner('tenant-a@example.com');
        [$cabinetB, $ownerB] = $this->activeCabinetWithOwner('tenant-b@example.com');

        Sanctum::actingAs($ownerA);
        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$this->envelope()]])
            ->assertOk();

        Sanctum::actingAs($ownerB);
        $this->assertSame(0, Appointment::query()->count());

        Sanctum::actingAs($ownerA);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(
            (int) $cabinetA->getKey(),
            (int) Appointment::withoutCabinetScope()->sole()->cabinet_id,
        );
        $this->assertNotSame((int) $cabinetB->getKey(), (int) $cabinetA->getKey());
    }

    public function test_a_tampered_envelope_is_rejected_without_writing(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('tamper@example.com');
        Sanctum::actingAs($owner);
        $envelope = $this->envelope();
        $envelope['payload']['status'] = 'cancelled';

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$envelope]])
            ->assertOk()
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('results.0.outcome', 'rejected')
            ->assertJsonPath('results.0.reason', 'payload_checksum_mismatch');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_the_envelope_shape_is_validated(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('shape@example.com');
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/sync/appointments/push', ['events' => []])
            ->assertStatus(422);

        $this->postJson('/api/v1/sync/appointments/push', [
            'events' => [['appointment_public_id' => 'not-a-uuid', 'version' => 1, 'action' => 'upsert', 'payload' => []]],
        ])->assertStatus(422);

        $this->postJson('/api/v1/sync/appointments/push', [
            'events' => [array_merge($this->envelope(), ['action' => 'explode'])],
        ])->assertStatus(422);
    }

    public function test_pushing_requires_permission_to_create_appointments(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('perm@example.com');
        // An approved cabinet member holding no role, and therefore no
        // appointment-write permission.
        $reader = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        Sanctum::actingAs($reader);

        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$this->envelope()]])
            ->assertForbidden();

        $this->assertSame(0, Appointment::withoutCabinetScope()->count());
    }

    public function test_pushing_requires_authentication(): void
    {
        $this->postJson('/api/v1/sync/appointments/push', ['events' => [$this->envelope()]])
            ->assertUnauthorized();
    }
}
