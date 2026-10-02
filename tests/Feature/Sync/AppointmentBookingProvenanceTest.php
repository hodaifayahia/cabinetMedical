<?php

namespace Tests\Feature\Sync;

use App\Enums\FamilyRelation;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Appointments\AppointmentSyncService;
use App\Services\Mobile\PatientBookingService;
use App\Services\Sync\AppointmentImporter;
use App\Services\Sync\ImportResult;
use App\Support\Appointments\BookingProvenance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Booking provenance across the sync boundary.
 *
 * The doctor's desktop must be able to tell that an appointment was booked
 * from the phone, and that a mother booked it for her son — without ever
 * receiving `booked_by_user_id` or `family_member_id`, which are the hosted
 * installation's own auto-increment keys and mean nothing locally.
 */
class AppointmentBookingProvenanceTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private AppointmentSyncService $sync;

    private AppointmentImporter $importer;

    private int $cabinetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$cabinet, $owner] = $this->activeCabinetWithOwner('provenance@example.com');
        $this->cabinetId = (int) $cabinet->getKey();
        $this->actingAs($owner);
        $this->sync = app(AppointmentSyncService::class);
        $this->importer = app(AppointmentImporter::class);
    }

    /**
     * The mobile account a booking is made from: Amine, with a demographic
     * profile and the number reception would call.
     */
    private function bookingAccount(): User
    {
        $user = User::factory()->create([
            'email' => 'amine@example.com',
            'name' => 'A. Benali',
            'phone' => '0660000001',
        ]);

        PatientProfile::factory()->create([
            'user_id' => $user->getKey(),
            'first_name' => 'Amine',
            'last_name' => 'Benali',
        ]);

        return $user;
    }

    /**
     * The cabinet dossier `PatientBookingService::resolvePatientRow()` creates
     * for a self booking: keyed by the account it belongs to.
     */
    private function dossierFor(User $user): Patient
    {
        $patient = Patient::factory()->create([
            'first_name' => 'Amine',
            'last_name' => 'Benali',
        ]);

        $patient->forceFill(['patient_user_id' => $user->getKey()])->save();

        return $patient;
    }

    /**
     * The dossier created for a family booking: keyed by the member, never by
     * the account holder.
     */
    private function dossierForMember(FamilyMember $member): Patient
    {
        $patient = Patient::factory()->create([
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);

        $patient->forceFill(['family_member_id' => $member->getKey()])->save();

        return $patient;
    }

    /**
     * An appointment written the way {@see PatientBookingService}
     * writes one: the booking columns are set outside mass assignment.
     *
     * @param  array<string, mixed>  $booking
     */
    private function appointmentBookedWith(Patient $patient, array $booking): Appointment
    {
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->getKey(),
        ]);

        $appointment->forceFill($booking)->saveQuietly();

        /** @var Appointment $fresh */
        $fresh = $appointment->fresh();

        return $fresh;
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
     * A payload as the hosted installation publishes it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'public_id' => (string) Str::uuid7(),
            'legacy_id' => 4242,
            'patient_id' => 999_999,
            'patient' => [
                'public_id' => (string) Str::uuid7(),
                'patient_number' => null,
                'first_name' => 'Yacine',
                'last_name' => 'Benali',
                'date_of_birth' => '2016-04-12',
                'gender' => 'male',
                'phone' => '0660000001',
                'email' => null,
            ],
            'booking' => [
                'channel' => 'mobile_patient',
                'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
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

    /**
     * Every key appearing anywhere in a nested array.
     *
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function keysAtEveryDepth(array $value): array
    {
        $keys = [];

        foreach ($value as $key => $nested) {
            $keys[] = (string) $key;

            if (is_array($nested)) {
                $keys = array_merge($keys, $this->keysAtEveryDepth($nested));
            }
        }

        return $keys;
    }

    public function test_a_self_booked_mobile_appointment_carries_its_channel_and_the_patient(): void
    {
        $user = $this->bookingAccount();
        $patient = $this->dossierFor($user);

        $payload = $this->sync->payload($this->appointmentBookedWith($patient, [
            'booked_by_user_id' => $user->getKey(),
            'booking_channel' => 'mobile_patient',
        ]));

        $this->assertSame([
            'channel' => 'mobile_patient',
            'booked_for' => ['type' => 'self', 'name' => 'Amine Benali', 'relation' => null],
            'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
        ], $payload['booking']);
    }

    public function test_a_family_booking_carries_the_members_name_and_relation(): void
    {
        $user = $this->bookingAccount();
        $member = FamilyMember::factory()->create([
            'owner_user_id' => $user->getKey(),
            'relation' => FamilyRelation::SON,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);
        $patient = Patient::factory()->create([
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);
        $patient->forceFill([
            'family_group_public_id' => $user->public_id,
            'family_relation' => FamilyRelation::SON->value,
            'family_contact_name' => 'Amine Benali',
        ])->saveQuietly();

        $payload = $this->sync->payload($this->appointmentBookedWith($patient, [
            'booked_by_user_id' => $user->getKey(),
            'family_member_id' => $member->getKey(),
            'booking_channel' => 'mobile_patient',
        ]));

        // The relation travels as the enum value; the receiving side owns the
        // translation, so a desktop in a different language stays correct.
        $this->assertSame(
            ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
            $payload['booking']['booked_for'],
        );
        $this->assertSame(
            ['name' => 'Amine Benali', 'phone' => '0660000001'],
            $payload['booking']['booked_by'],
        );
        $this->assertSame($user->public_id, $payload['patient']['family_group_public_id']);
        $this->assertSame('son', $payload['patient']['family_relation']);
        $this->assertSame('Amine Benali', $payload['patient']['family_contact_name']);
    }

    public function test_a_staff_created_appointment_carries_no_provenance(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->getKey()]);

        $payload = $this->sync->payload($appointment);

        // Absent, not null. `appointment_sync_events.payload_sha256` is a hash
        // of this array that outlives the deploy, and `reconcileRecent()`
        // compares a fresh hash against it on every pull and every push. A
        // `"booking":null` would change the encoding of every staff-created
        // appointment ever published and make the first sync after the
        // upgrade version-bump and republish up to 500 unchanged appointments
        // per poll on both installations.
        $this->assertArrayNotHasKey('booking', $payload);
    }

    public function test_an_appointment_without_provenance_hashes_as_it_did_before_the_change(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->getKey()]);

        // The payload the previous release produced for this row, rebuilt by
        // dropping the only key this change can add.
        $previousRelease = $this->sync->payload($appointment);
        unset($previousRelease['booking']);

        $encoded = json_encode(
            $previousRelease,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->assertSame(
            hash('sha256', $encoded),
            $this->sync->payloadSha256($appointment),
        );
    }

    public function test_a_staff_appointment_published_before_the_upgrade_is_not_republished(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->getKey()]);
        $this->sync->publishUpsert($appointment);

        $versionBefore = (int) $appointment->fresh()->sync_version;
        $eventsBefore = AppointmentSyncEvent::withoutCabinetScope()->count();

        $this->sync->reconcileRecent();

        // The stored hash still matches, so the appointment is left alone.
        // Otherwise every cabinet's first poll after the deploy would bump and
        // republish its 500 most recent appointments inside one request.
        $this->assertSame($versionBefore, (int) $appointment->fresh()->sync_version);
        $this->assertSame($eventsBefore, AppointmentSyncEvent::withoutCabinetScope()->count());
    }

    public function test_no_local_foreign_key_ever_crosses_the_wire(): void
    {
        $user = $this->bookingAccount();
        $member = FamilyMember::factory()->create([
            'owner_user_id' => $user->getKey(),
            'relation' => FamilyRelation::SON,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);
        $patient = Patient::factory()->create(['first_name' => 'Yacine', 'last_name' => 'Benali']);

        $payload = $this->sync->payload($this->appointmentBookedWith($patient, [
            'booked_by_user_id' => $user->getKey(),
            'family_member_id' => $member->getKey(),
            'booking_channel' => 'mobile_patient',
        ]));

        $keys = $this->keysAtEveryDepth($payload);

        // The receiving installation has no matching `users` row and no
        // `family_members` content at all: either key would break its foreign
        // keys or silently name an unrelated person.
        $this->assertNotContains('booked_by_user_id', $keys);
        $this->assertNotContains('family_member_id', $keys);
    }

    public function test_an_import_stores_the_context_and_leaves_both_foreign_keys_null(): void
    {
        $result = $this->importer->import($this->cabinetId, $this->event($this->payload()));

        $this->assertSame(ImportResult::OUTCOME_CREATED, $result->outcome);

        $appointment = Appointment::query()->sole();
        $this->assertSame([
            'channel' => 'mobile_patient',
            'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
            'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
        ], $appointment->booking_context);

        // The publisher's keys are meaningless here and are never written.
        $this->assertNull($appointment->booked_by_user_id);
        $this->assertNull($appointment->family_member_id);
    }

    public function test_a_payload_from_a_publisher_that_predates_provenance_imports_cleanly(): void
    {
        $payload = $this->payload();
        unset($payload['booking']);

        $result = $this->importer->import($this->cabinetId, $this->event($payload));

        $this->assertSame(ImportResult::OUTCOME_CREATED, $result->outcome);
        $this->assertNull(Appointment::query()->sole()->booking_context);
    }

    public function test_a_malformed_booking_block_is_discarded_rather_than_stored(): void
    {
        $result = $this->importer->import($this->cabinetId, $this->event(
            $this->payload(['booking' => 'mobile_patient']),
        ));

        $this->assertSame(ImportResult::OUTCOME_CREATED, $result->outcome);
        // Reception reads this as fact; a shape we do not recognise is worth
        // less than nothing.
        $this->assertNull(Appointment::query()->sole()->booking_context);
    }

    public function test_provenance_survives_the_round_trip_back_to_the_publisher(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        $reserialised = $this->sync->payload(Appointment::query()->sole());

        // hosted -> desktop -> re-push -> hosted. The local foreign keys are
        // null on an imported row, so the block has to come back out of
        // `booking_context` or the doctor's own edit would erase it.
        $this->assertSame($payload['booking'], $reserialised['booking']);
    }

    public function test_provenance_alone_is_not_a_version_conflict(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        // Same version, same clinical content, different provenance — which is
        // exactly what an old-format publisher and a new-format one produce for
        // one appointment. Treating that as a divergence would stall the
        // stream on a change that never happened.
        $result = $this->importer->import($this->cabinetId, $this->event($this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'booking' => null,
            'version' => 1,
        ])));

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame('not_newer', $result->reason);
        $this->assertSame($payload['booking'], Appointment::query()->sole()->booking_context);
    }

    public function test_a_deleted_family_row_degrades_to_null_instead_of_failing_the_save(): void
    {
        $user = $this->bookingAccount();
        $patient = Patient::factory()->create(['first_name' => 'Yacine', 'last_name' => 'Benali']);

        $appointment = $this->appointmentBookedWith($patient, [
            'booked_by_user_id' => $user->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);

        // The account is gone while this in-memory model still points at it —
        // what a partially restored backup, or a stale instance mid-request,
        // looks like. Publication runs on every appointment write, so it must
        // drop the line rather than throw during a clinician's save.
        $user->delete();

        $payload = $this->sync->payload($appointment);

        $this->assertSame('mobile_patient', $payload['booking']['channel']);
        $this->assertNull($payload['booking']['booked_by']);
    }

    public function test_a_publisher_that_says_nothing_about_provenance_does_not_erase_it(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        // Exactly the payload a publisher rolled back to the pre-provenance
        // release emits for its next edit — the key is absent, not null — and
        // at a NEWER version, which is the normal case for any subsequent
        // change. On an imported row both booking foreign keys are null, so
        // an erasure here would be permanent and would then propagate back.
        $older = $this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'reason' => 'Contrôle',
            'version' => 2,
        ]);
        unset($older['booking']);

        $result = $this->importer->import($this->cabinetId, $this->event($older));

        $this->assertSame(ImportResult::OUTCOME_UPDATED, $result->outcome);

        $appointment = Appointment::query()->sole();
        $this->assertSame('Contrôle', $appointment->reason);
        $this->assertSame($payload['booking'], $appointment->booking_context);
    }

    public function test_a_publisher_that_sends_a_new_block_still_replaces_the_stored_one(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->cabinetId, $this->event($payload));

        // The guard above must key off the key's presence, not off the value:
        // a publisher that does send provenance is still authoritative, and a
        // corrected block has to be able to land.
        $corrected = $this->payload([
            'public_id' => $payload['public_id'],
            'patient' => $payload['patient'],
            'version' => 2,
            'booking' => [
                'channel' => 'mobile_patient',
                'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'daughter'],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000002'],
            ],
        ]);

        $this->importer->import($this->cabinetId, $this->event($corrected));

        $this->assertSame($corrected['booking'], Appointment::query()->sole()->booking_context);
    }

    public function test_an_oversized_block_from_the_wire_is_bounded_before_it_is_stored(): void
    {
        $result = $this->importer->import($this->cabinetId, $this->event($this->payload([
            'booking' => [
                'channel' => str_repeat('A', 200_000),
                'booked_for' => [
                    'type' => 'family',
                    'name' => str_repeat('B', 200_000),
                    'relation' => 'son',
                ],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => str_repeat('9', 100_000)],
            ],
        ])));

        $this->assertSame(ImportResult::OUTCOME_CREATED, $result->outcome);

        $context = Appointment::query()->sole()->booking_context;

        // The local producer cannot generate this — `booking_channel` is
        // varchar(20) — so the only bound is on the sending side, which is
        // exactly the side an importer must not trust. Unbounded, the block
        // would be copied into every later event row on both installations
        // and would grow a 100-envelope push past `post_max_size`, stalling
        // `push_cursor` for good.
        $this->assertLessThanOrEqual(120, mb_strlen($context['channel']));
        $this->assertLessThanOrEqual(120, mb_strlen($context['booked_for']['name']));
        $this->assertLessThanOrEqual(120, mb_strlen($context['booked_by']['phone']));
    }

    public function test_deleting_a_family_member_does_not_rewrite_the_visit_as_the_account_holders_own(): void
    {
        $user = $this->bookingAccount();
        $member = FamilyMember::factory()->create([
            'owner_user_id' => $user->getKey(),
            'relation' => FamilyRelation::SON,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);
        $patient = $this->dossierForMember($member);

        $appointment = $this->appointmentBookedWith($patient, [
            'booked_by_user_id' => $user->getKey(),
            'family_member_id' => $member->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);

        // `appointments.family_member_id` is nullOnDelete and FamilyMember has
        // no soft deletes, so this wipes the column on every appointment ever
        // booked for Yacine. `FamilyMemberController::destroy()` guards only a
        // dependent's upcoming appointments, so a past visit reaches here.
        $member->delete();

        /** @var Appointment $reloaded */
        $reloaded = $appointment->fresh();
        $bookedFor = $this->sync->payload($reloaded)['booking']['booked_for'];

        // Reporting 'self' would rename the visit after the account holder and,
        // because the live block outranks the stored context, republish that
        // over the peer installation's correct `booking_context`.
        $this->assertSame('family', $bookedFor['type']);
        $this->assertSame('Yacine Benali', $bookedFor['name']);
        $this->assertNull($bookedFor['relation']);

        // The staff API and the desktop agenda read the same degraded row
        // through BookingProvenance and must agree with the wire.
        $this->assertSame($bookedFor, BookingProvenance::for($reloaded)['booked_for']);
    }

    public function test_provenance_is_backfilled_when_both_installations_hold_the_same_version(): void
    {
        $old = $this->payload(['version' => 2]);
        unset($old['booking']);

        $this->importer->import($this->cabinetId, $this->event($old));
        $this->assertNull(Appointment::query()->sole()->booking_context);

        // The hosted install republishes the same appointment with provenance
        // at a version this desktop already reached. Clinically identical, so
        // it is correctly `not_newer` — but discarding the block would leave
        // every appointment that existed before the upgrade permanently blank
        // on the doctor's screen, since a past appointment is never edited
        // again.
        $withProvenance = $this->payload([
            'public_id' => $old['public_id'],
            'patient' => $old['patient'],
            'version' => 2,
        ]);

        $result = $this->importer->import($this->cabinetId, $this->event($withProvenance));

        $this->assertSame(ImportResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame('not_newer', $result->reason);
        $this->assertSame($withProvenance['booking'], Appointment::query()->sole()->booking_context);
    }

    public function test_a_second_payload_build_resolves_no_booking_relation_twice(): void
    {
        $user = $this->bookingAccount();
        $member = FamilyMember::factory()->create([
            'owner_user_id' => $user->getKey(),
            'relation' => FamilyRelation::SON,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);
        $appointment = $this->appointmentBookedWith($this->dossierForMember($member), [
            'booked_by_user_id' => $user->getKey(),
            'family_member_id' => $member->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);

        $this->sync->payload($appointment);

        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            foreach (['family_members', 'patient_profiles', 'users', 'patients'] as $table) {
                if (str_contains($query->sql, $table)) {
                    $tables[] = $table;
                }
            }
        });

        // `publish()` builds the payload and `envelopesFor()` builds it twice
        // more; `publishUpsert()` runs inside the transaction holding the
        // cabinet's slot lock, which every other patient and receptionist is
        // queued behind. The memo `appointmentPatient()` was written for has
        // to cover the three booking lookups too.
        $this->sync->payloadSha256($appointment);

        $this->assertSame([], $tables);
    }
}
