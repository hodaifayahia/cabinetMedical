<?php

namespace Tests\Feature\Sync;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\Patient;
use App\Services\Appointments\AppointmentSyncService;
use App\Services\Sync\AppointmentImporter;
use App\Services\Sync\ImportResult;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

class AppointmentImporterTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private AppointmentImporter $importer;

    private int $cabinetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$cabinet, $owner] = $this->activeCabinetWithOwner('importer@example.com');
        $this->cabinetId = (int) $cabinet->getKey();
        $this->actingAs($owner);
        $this->importer = app(AppointmentImporter::class);
    }

    /**
     * Build a remote event with a correct checksum, the way the publisher does.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $payload, array $overrides = []): array
    {
        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return array_merge([
            'cursor' => 1,
            'event_id' => (string) Str::uuid7(),
            'appointment_public_id' => $payload['public_id'],
            'version' => $payload['version'],
            'action' => 'upsert',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $encoded),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'public_id' => (string) Str::uuid7(),
            'legacy_id' => 4242,
            // Deliberately a value that does not exist locally: the importer
            // must never trust the publisher's primary key.
            'patient_id' => 999_999,
            'patient' => [
                'public_id' => (string) Str::uuid7(),
                'patient_number' => null,
                'first_name' => 'Amina',
                'last_name' => 'Benali',
                'date_of_birth' => '1990-04-12',
                'gender' => 'female',
                'phone' => '0551223344',
                'email' => null,
            ],
            'appointment_date' => '2026-09-15',
            'starts_at' => '2026-09-15T09:00:00+00:00',
            'ends_at' => '2026-09-15T09:30:00+00:00',
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
            'created_at' => '2026-09-01T08:00:00+00:00',
            'updated_at' => '2026-09-01T08:00:00+00:00',
        ], $overrides);
    }

    public function test_an_unknown_appointment_creates_the_patient_and_the_appointment(): void
    {
        $payload = $this->payload();

        $result = $this->importer->import($this->cabinetId, $this->event($payload));

        $this->assertSame(ImportResult::OUTCOME_CREATED, $result->outcome);
        $this->assertSame(1, Patient::query()->count());

        $appointment = Appointment::query()->sole();
        $this->assertSame($payload['public_id'], $appointment->public_id);
        $this->assertSame(1, (int) $appointment->sync_version);
        $this->assertSame($this->cabinetId, (int) $appointment->cabinet_id);
    }

    public function test_imported_family_identity_is_persisted_with_the_patient(): void
    {
        $groupId = (string) Str::uuid7();
        $payload = $this->payload([
            'patient' => array_merge($this->payload()['patient'], [
                'family_group_public_id' => $groupId,
                'family_relation' => 'father',
                'family_contact_name' => 'Amina Benali',
            ]),
            'booking' => [
                'channel' => 'mobile_patient',
                'booked_for' => [
                    'type' => 'family',
                    'name' => 'Yacine Benali',
                    'relation' => 'father',
                ],
                'booked_by' => ['name' => 'Amina Benali', 'phone' => '0551223344'],
            ],
        ]);

        $this->importer->import($this->cabinetId, $this->event($payload));

        $patient = Patient::query()->sole();
        $this->assertSame($groupId, $patient->family_group_public_id);
        $this->assertSame('father', $patient->family_relation);
        $this->assertSame('Amina Benali', $patient->family_contact_name);
    }

    public function test_same_version_replay_backfills_new_family_identity(): void
    {
        $payload = $this->payload([
            'booking' => [
                'channel' => 'mobile_patient',
                'booked_for' => [
                    'type' => 'family',
                    'name' => 'Yacine Benali',
                    'relation' => 'father',
                ],
                'booked_by' => ['name' => 'Amina Benali', 'phone' => '0551223344'],
            ],
        ]);
        $this->importer->import($this->cabinetId, $this->event($payload));
        $groupId = (string) Str::uuid7();

        $replay = $payload;
        $replay['patient'] += [
            'family_group_public_id' => $groupId,
            'family_relation' => 'father',
            'family_contact_name' => 'Amina Benali',
        ];
        $result = $this->importer->import($this->cabinetId, $this->event($replay));

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $patient = Patient::query()->sole();
        $this->assertSame($groupId, $patient->family_group_public_id);
        $this->assertSame('father', $patient->family_relation);
        $this->assertSame('Amina Benali', $patient->family_contact_name);
    }

    public function test_the_appointment_time_survives_the_timezone_round_trip(): void
    {
        // The remote publishes UTC; this installation runs in Africa/Algiers.
        // Assigning the ISO string straight to a datetime cast would store its
        // wall-clock digits and re-read them as local time, putting every
        // synced appointment an hour out.
        $this->importer->import($this->cabinetId, $this->event($this->payload([
            'starts_at' => '2026-09-15T09:00:00+00:00',
            'ends_at' => '2026-09-15T09:30:00+00:00',
        ])));

        $appointment = Appointment::query()->sole();

        $this->assertSame(
            '2026-09-15T09:00:00+00:00',
            $appointment->starts_at->utc()->toIso8601String(),
        );
        $this->assertSame(
            '2026-09-15T09:30:00+00:00',
            $appointment->ends_at->utc()->toIso8601String(),
        );
        // The date column tracks the localised start, so day filters agree
        // with the time the clinic sees.
        $this->assertSame('2026-09-15', $appointment->appointment_date->toDateString());
    }

    public function test_an_existing_patient_keeps_its_local_id(): void
    {
        $local = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'phone' => '0551223344',
        ]);

        $this->importer->import($this->cabinetId, $this->event($this->payload()));

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(
            $local->getKey(),
            (int) Appointment::query()->sole()->patient_id,
            'The appointment must attach to the patient this installation already holds.',
        );
    }

    public function test_the_publishers_patient_id_is_never_used_directly(): void
    {
        Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'phone' => '0551223344',
        ]);

        $this->importer->import($this->cabinetId, $this->event($this->payload()));

        $this->assertNotSame(999_999, (int) Appointment::query()->sole()->patient_id);
    }

    public function test_a_newer_version_updates_the_status(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        $result = $this->importer->import($this->cabinetId, $this->event($this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'status' => 'confirmed',
            'confirmed_at' => '2026-09-02T10:00:00+00:00',
            'version' => 2,
        ])));

        $this->assertSame(ImportResult::OUTCOME_UPDATED, $result->outcome);
        $appointment = Appointment::query()->sole();
        $this->assertSame(AppointmentStatus::CONFIRMED, $appointment->status);
        $this->assertSame(2, (int) $appointment->sync_version);
    }

    public function test_replaying_the_same_event_changes_nothing(): void
    {
        $event = $this->event($this->payload());
        $this->importer->import($this->cabinetId, $event);

        $result = $this->importer->import($this->cabinetId, $event);

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame('not_newer', $result->reason);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_same_version_divergence_is_reported_rather_than_hidden(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        // Both sides edited independently and landed on the same version.
        $result = $this->importer->import($this->cabinetId, $this->event($this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'status' => 'cancelled',
            'version' => 1,
        ])));

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame('version_conflict', $result->reason);
        // The clinician's own record is kept, not silently overwritten.
        $this->assertSame(
            AppointmentStatus::SCHEDULED,
            Appointment::query()->sole()->status,
        );
    }

    public function test_an_older_version_never_overwrites_newer_local_state(): void
    {
        $payload = $this->payload(['status' => 'completed', 'version' => 5]);
        $this->importer->import($this->cabinetId, $this->event($payload));

        $result = $this->importer->import($this->cabinetId, $this->event($this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'status' => 'scheduled',
            'version' => 3,
        ])));

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame(AppointmentStatus::COMPLETED, Appointment::query()->sole()->status);
    }

    public function test_an_imported_change_is_recorded_but_never_queued_for_push(): void
    {
        AppointmentSyncEvent::query()->delete();

        $this->importer->import($this->cabinetId, $this->event($this->payload()));

        // Recorded, so this cabinet's history stays complete and reconciliation
        // does not mistake the appointment for an unpublished local change.
        $event = AppointmentSyncEvent::withoutCabinetScope()->sole();
        // Marked, so the push phase never sends it back to the installation it
        // came from — which would bounce the appointment between the two.
        $this->assertSame(AppointmentSyncEvent::STATUS_IMPORTED, $event->status);
    }

    public function test_reconciliation_does_not_republish_an_imported_appointment(): void
    {
        $this->importer->import($this->cabinetId, $this->event($this->payload()));

        app(AppointmentSyncService::class)->reconcileRecent();

        // A republish here would bump the local version past the remote's, and
        // the appointment would stop receiving further updates.
        $this->assertSame(1, (int) Appointment::query()->sole()->sync_version);
        $this->assertSame(1, AppointmentSyncEvent::withoutCabinetScope()->count());
    }

    public function test_a_tombstone_soft_deletes_the_local_appointment(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        $result = $this->importer->import($this->cabinetId, $this->event(
            $this->payload([
                'public_id' => $payload['public_id'],
                'patient' => $payload['patient'],
                'version' => 2,
            ]),
            ['action' => 'delete'],
        ));

        $this->assertSame(ImportResult::OUTCOME_DELETED, $result->outcome);
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(1, Appointment::query()->withTrashed()->count());
    }

    public function test_a_tombstone_for_an_unknown_appointment_is_ignored(): void
    {
        $result = $this->importer->import(
            $this->cabinetId,
            $this->event($this->payload(), ['action' => 'delete']),
        );

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame('unknown_tombstone', $result->reason);
        $this->assertSame(0, Appointment::query()->withTrashed()->count());
    }

    public function test_a_tampered_payload_is_refused(): void
    {
        $event = $this->event($this->payload());
        $event['payload']['status'] = 'completed';

        $result = $this->importer->import($this->cabinetId, $event);

        $this->assertSame(ImportResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame('payload_checksum_mismatch', $result->reason);
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_malformed_event_is_refused_without_touching_the_database(): void
    {
        $result = $this->importer->import($this->cabinetId, ['appointment_public_id' => null]);

        $this->assertSame(ImportResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame('malformed_event', $result->reason);
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_an_event_without_a_patient_block_is_refused(): void
    {
        $payload = $this->payload(['patient' => null]);

        $result = $this->importer->import($this->cabinetId, $this->event($payload));

        $this->assertSame(ImportResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame('patient_unresolvable', $result->reason);
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_importing_into_another_users_cabinet_is_refused(): void
    {
        [$otherCabinet, $otherOwner] = $this->activeCabinetWithOwner('intruder@example.com');
        $this->actingAs($otherOwner);

        // The tenant trait would otherwise reassign the new rows to the acting
        // user's cabinet, silently writing into the wrong clinic's records.
        $result = $this->importer->import($this->cabinetId, $this->event($this->payload()));

        $this->assertSame(ImportResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame('cabinet_mismatch', $result->reason);
        $this->assertSame(0, Appointment::withoutCabinetScope()->count());
        $this->assertNotNull($otherCabinet);
    }

    public function test_a_payload_cannot_move_an_appointment_to_another_cabinet(): void
    {
        [$otherCabinet] = $this->activeCabinetWithOwner('elsewhere@example.com');
        $payload = $this->payload();
        $payload['cabinet_id'] = $otherCabinet->getKey();

        $this->importer->import($this->cabinetId, $this->event($payload));

        $this->assertSame(
            $this->cabinetId,
            (int) Appointment::withoutCabinetScope()->sole()->cabinet_id,
        );
    }
}
