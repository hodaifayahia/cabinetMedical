<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

class AppointmentMobileSyncTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cursor_stream_and_acknowledgement_are_cabinet_scoped(): void
    {
        [$cabinetA, $ownerA] = $this->activeCabinetWithOwner('sync-a@example.com');
        [$cabinetB, $ownerB] = $this->activeCabinetWithOwner('sync-b@example.com');

        Sanctum::actingAs($ownerA);
        $appointmentA = Appointment::factory()->for(Patient::factory()->create())->create();
        $eventA = AppointmentSyncEvent::query()->sole();

        Sanctum::actingAs($ownerB);
        $appointmentB = Appointment::factory()->for(Patient::factory()->create())->create();
        $eventB = AppointmentSyncEvent::query()->sole();

        Sanctum::actingAs($ownerA);
        $response = $this->getJson('/api/v1/sync/appointments?cursor=0&limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_id', $eventA->event_id)
            ->assertJsonPath('data.0.appointment_public_id', $appointmentA->public_id)
            ->assertJsonPath('data.0.action', 'upsert')
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('meta.next_cursor', $eventA->id)
            ->assertJsonPath('meta.has_more', false);

        $this->postJson('/api/v1/sync/appointments/ack', [
            // A cursor beyond another cabinet's row still acknowledges only A.
            'cursor' => max($eventA->id, $eventB->id),
        ])->assertOk()
            ->assertJsonPath('acknowledged_count', 1);

        $this->assertSame(
            AppointmentSyncEvent::STATUS_ACKNOWLEDGED,
            AppointmentSyncEvent::withoutCabinetScope()->findOrFail($eventA->id)->status,
        );
        $this->assertSame(
            AppointmentSyncEvent::STATUS_PENDING,
            AppointmentSyncEvent::withoutCabinetScope()->findOrFail($eventB->id)->status,
        );
        $this->assertSame($cabinetA->id, $appointmentA->cabinet_id);
        $this->assertSame($cabinetB->id, $appointmentB->cabinet_id);
    }

    public function test_public_id_version_conflicts_and_soft_delete_tombstones_are_exposed(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('versions@example.com');
        Sanctum::actingAs($owner);
        $appointment = Appointment::factory()->for(Patient::factory()->create())->create();

        $this->assertTrue(Str::isUuid((string) $appointment->public_id));
        $this->assertSame(1, $appointment->sync_version);

        $this->patchJson('/api/v1/appointments/'.$appointment->public_id, [
            'reason' => 'Controle mobile',
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.public_id', $appointment->public_id)
            ->assertJsonPath('data.sync_version', 2);

        $this->patchJson('/api/v1/appointments/'.$appointment->public_id, [
            'reason' => 'Ecriture obsolete',
            'expected_version' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('reason', 'sync_version_conflict')
            ->assertJsonPath('current_version', 2);

        $this->withHeader('If-Match', '"2"')
            ->deleteJson('/api/v1/appointments/'.$appointment->public_id)
            ->assertOk();

        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
        $tombstone = AppointmentSyncEvent::query()->orderByDesc('version')->firstOrFail();
        $this->assertSame('delete', $tombstone->action);
        $this->assertSame(3, $tombstone->version);
        $this->assertSame($appointment->public_id, $tombstone->appointment_public_id);
        $this->assertNotNull($tombstone->payload['deleted_at']);
        $this->assertDatabaseCount('appointment_sync_events', 3);
    }

    public function test_mobile_booking_idempotency_replays_one_appointment_and_rejects_key_reuse(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('idempotency@example.com');
        $startsAt = CarbonImmutable::now()->addDays(4)->startOfDay()->setTime(9, 0);
        $this->configureBookableDoctor($owner, $startsAt);
        Sanctum::actingAs($owner);
        $patient = Patient::factory()->create();
        $payload = [
            'patient_id' => $patient->id,
            'starts_at' => $startsAt->toIso8601String(),
            'reason' => 'Demande mobile',
            'status' => 'scheduled',
        ];

        $this->withHeader('Idempotency-Key', 'mobile-booking-0001')
            ->postJson('/api/v1/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.sync_version', 1);

        $this->withHeader('Idempotency-Key', 'mobile-booking-0001')
            ->postJson('/api/v1/appointments', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_sync_events', 1);

        $this->withHeader('Idempotency-Key', 'mobile-booking-0001')
            ->postJson('/api/v1/appointments', [
                ...$payload,
                'reason' => 'Une autre demande',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'idempotency_key_reused');
    }

    public function test_manual_gray_sync_action_republishes_an_acknowledged_snapshot(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('manual-sync@example.com');
        $this->actingAs($owner);
        $appointment = Appointment::factory()->for(Patient::factory()->create())->create();
        $initial = AppointmentSyncEvent::query()->sole();
        $initial->update([
            'status' => AppointmentSyncEvent::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $owner->id,
        ]);

        $this->post(route('app.appointments.mobile-sync', $appointment))
            ->assertRedirect();

        $appointment->refresh();
        $this->assertSame(2, $appointment->sync_version);
        $latest = AppointmentSyncEvent::query()->orderByDesc('version')->firstOrFail();
        $this->assertSame(AppointmentSyncEvent::STATUS_PENDING, $latest->status);
        $this->assertSame(2, $latest->version);

        $this->get(route('app.appointments.index', [
            'date' => $appointment->appointment_date?->toDateString(),
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.data.0.mobile_sync.state', 'pending')
                ->where('appointments.data.0.sync_version', 2)
                ->where('permissions.syncMobile', true),
            );
    }

    public function test_common_day_sync_republishes_only_the_selected_day_for_the_current_cabinet(): void
    {
        [$cabinetA, $ownerA] = $this->activeCabinetWithOwner('day-sync-a@example.com');
        [, $ownerB] = $this->activeCabinetWithOwner('day-sync-b@example.com');
        $syncDate = CarbonImmutable::today()->addDays(3);

        $this->actingAs($ownerA);
        $first = Appointment::factory()->for(Patient::factory()->create())->create([
            'starts_at' => $syncDate->setTime(9, 0),
            'ends_at' => $syncDate->setTime(9, 30),
        ]);
        $second = Appointment::factory()->for(Patient::factory()->create())->create([
            'starts_at' => $syncDate->setTime(10, 0),
            'ends_at' => $syncDate->setTime(10, 30),
        ]);
        $anotherDay = Appointment::factory()->for(Patient::factory()->create())->create([
            'starts_at' => $syncDate->addDay()->setTime(9, 0),
            'ends_at' => $syncDate->addDay()->setTime(9, 30),
        ]);

        $this->actingAs($ownerB);
        $otherCabinet = Appointment::factory()->for(Patient::factory()->create())->create([
            'starts_at' => $syncDate->setTime(11, 0),
            'ends_at' => $syncDate->setTime(11, 30),
        ]);

        AppointmentSyncEvent::withoutCabinetScope()->update([
            'status' => AppointmentSyncEvent::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($ownerA)
            ->post(route('app.appointments.mobile-sync-day'), [
                'date' => $syncDate->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(2, Appointment::withoutCabinetScope()->findOrFail($first->id)->sync_version);
        $this->assertSame(2, Appointment::withoutCabinetScope()->findOrFail($second->id)->sync_version);
        $this->assertSame(1, Appointment::withoutCabinetScope()->findOrFail($anotherDay->id)->sync_version);
        $this->assertSame(1, Appointment::withoutCabinetScope()->findOrFail($otherCabinet->id)->sync_version);

        $pendingPublicIds = AppointmentSyncEvent::withoutCabinetScope()
            ->where('cabinet_id', $cabinetA->id)
            ->where('status', AppointmentSyncEvent::STATUS_PENDING)
            ->pluck('appointment_public_id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$first->public_id, $second->public_id],
            $pendingPublicIds,
        );
    }

    public function test_common_day_sync_requires_appointment_update_permission(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('day-sync-owner@example.com');
        $syncDate = CarbonImmutable::today()->addDays(2);
        $this->actingAs($owner);
        $appointment = Appointment::factory()->for(Patient::factory()->create())->create([
            'starts_at' => $syncDate->setTime(9, 0),
            'ends_at' => $syncDate->setTime(9, 30),
        ]);

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->id,
            'approved_at' => now(),
        ]);
        $member->givePermissionTo('appointments.view');

        $this->actingAs($member)
            ->post(route('app.appointments.mobile-sync-day'), [
                'date' => $syncDate->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame(1, $appointment->fresh()->sync_version);
    }

    public function test_sync_pull_repairs_bulk_updates_that_bypass_model_events(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('bulk-sync@example.com');
        Sanctum::actingAs($owner);
        $appointment = Appointment::factory()->for(Patient::factory()->create())->create();
        $initialCursor = AppointmentSyncEvent::query()->sole()->id;

        Appointment::withoutCabinetScope()
            ->whereKey($appointment->id)
            ->update([
                'reason' => 'Updated by a bulk workflow',
                'updated_at' => now()->addSecond(),
            ]);

        $this->getJson('/api/v1/sync/appointments?cursor='.$initialCursor)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.payload.reason', 'Updated by a bulk workflow');

        $this->assertSame(2, $appointment->fresh()->sync_version);
        $this->assertDatabaseCount('appointment_sync_events', 2);
    }

    public function test_sync_endpoints_require_appointment_view_permission(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('sync-owner@example.com');
        Sanctum::actingAs($owner);
        Appointment::factory()->for(Patient::factory()->create())->create();

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->id,
            'approved_at' => now(),
        ]);
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/sync/appointments')->assertForbidden();
        $this->postJson('/api/v1/sync/appointments/ack', ['cursor' => 1])
            ->assertForbidden();
    }
}
