<?php

namespace Tests\Feature\Api\Mobile\Booking;

use App\Enums\FamilyRelation;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Patient booking through POST /api/v1/my/appointments. The booking patient
 * has cabinet_id = null, so every assertion also checks that tenancy was
 * assigned explicitly (cabinet_id on the dossier and the appointment).
 */
class PatientBookingTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A deterministic, always-future bookable slot: the 15th of next month
     * at 09:00, with a matching weekly schedule row and an open month.
     */
    private function bookableSlot(): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->addMonthsNoOverflow(1)
            ->startOfMonth()
            ->addDays(14)
            ->setTime(9, 0);
    }

    /**
     * @return array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}
     */
    private function makeBookableClinic(CarbonImmutable $slotStart, bool $openMonth = true): array
    {
        $clinic = $this->makeListedClinic();

        DoctorSchedule::factory()->create([
            'doctor_id' => $clinic['doctor']->getKey(),
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'day_of_week' => Weekday::from((int) $slotStart->dayOfWeekIso),
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'slot_duration' => 30,
            'is_active' => true,
        ]);

        if ($openMonth) {
            DoctorOpenMonth::factory()->create([
                'doctor_id' => $clinic['doctor']->getKey(),
                'cabinet_id' => $clinic['cabinet']->getKey(),
                'year' => (int) $slotStart->year,
                'month' => (int) $slotStart->month,
                'is_open' => true,
            ]);
        }

        return $clinic;
    }

    public function test_patient_books_a_slot_for_self(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $response = $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
            'reason' => 'Douleurs abdominales',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.booked_for.type', 'self')
            ->assertJsonPath('data.doctor.id', $doctor->getKey())
            ->assertJsonPath('data.clinic.id', $cabinet->getKey())
            ->assertJsonMissingPath('data.reception_notes');

        // The dossier was auto-created for this cabinet and claimed by the account.
        $this->assertDatabaseHas('patients', [
            'cabinet_id' => $cabinet->getKey(),
            'patient_user_id' => $patient->getKey(),
        ]);

        // Tenancy and channel are explicit on the created appointment.
        $this->assertDatabaseHas('appointments', [
            'cabinet_id' => $cabinet->getKey(),
            'booked_by_user_id' => $patient->getKey(),
            'family_member_id' => null,
            'booking_channel' => 'mobile_patient',
            'status' => 'scheduled',
        ]);

        $dossier = Patient::withoutCabinetScope()
            ->where('patient_user_id', $patient->getKey())
            ->firstOrFail();

        // patient_number follows the existing cabinet scheme (GeneratePatientNumberAction).
        $this->assertMatchesRegularExpression('/^PAT-\d{8}-[A-Z0-9]{6}$/', (string) $dossier->patient_number);
        $this->assertSame($patient->phone, $dossier->phone);
        $this->assertNotEmpty($patient->public_id);
        $this->assertSame($patient->public_id, $dossier->family_group_public_id);
        $this->assertNull($dossier->family_relation);
        $this->assertSame(
            trim($patient->patientProfile->first_name.' '.$patient->patientProfile->last_name),
            $dossier->family_contact_name,
        );
    }

    public function test_booking_twice_reuses_the_same_dossier_row(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        foreach ([$slot, $slot->addMinutes(30)] as $startsAt) {
            $this->postJson('/api/v1/my/appointments', [
                'doctor_id' => $doctor->getKey(),
                'starts_at' => $startsAt->toIso8601String(),
            ])->assertStatus(201);
        }

        $this->assertSame(1, Patient::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->where('patient_user_id', $patient->getKey())
            ->count());

        $this->assertSame(2, Appointment::withoutCabinetScope()
            ->where('booked_by_user_id', $patient->getKey())
            ->count());
    }

    public function test_double_booking_the_same_slot_is_rejected(): void
    {
        $slot = $this->bookableSlot();
        ['doctor' => $doctor] = $this->makeBookableClinic($slot);

        $first = $this->makePatientUser();
        Sanctum::actingAs($first, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertStatus(201);

        $second = $this->makePatientUser();
        Sanctum::actingAs($second, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'slot_unavailable');

        $this->assertSame(0, Appointment::withoutCabinetScope()
            ->where('booked_by_user_id', $second->getKey())
            ->count());
    }

    public function test_starts_at_with_a_utc_offset_is_normalized_to_local_time(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        // The same instant serialized in UTC (a very common client default):
        // 09:00 Africa/Algiers == 08:00Z. It must book — and store — the
        // 09:00 local slot, not a shifted naive time.
        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->setTimezone('UTC')->toIso8601String(),
        ])->assertStatus(201);

        $appointment = Appointment::withoutCabinetScope()
            ->where('booked_by_user_id', $patient->getKey())
            ->firstOrFail();

        $this->assertSame($slot->format('Y-m-d H:i:s'), $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame((int) $cabinet->getKey(), (int) $appointment->cabinet_id);
    }

    public function test_a_forged_utc_offset_cannot_double_book_a_taken_slot(): void
    {
        // Last slot of the 09:00–12:00 window.
        $slot = $this->bookableSlot()->setTime(11, 30);
        ['doctor' => $doctor] = $this->makeBookableClinic($slot);

        $first = $this->makePatientUser();
        Sanctum::actingAs($first, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertStatus(201);

        $second = $this->makePatientUser();
        Sanctum::actingAs($second, ['mobile']);

        // "11:30Z" is 12:30 local. Before offsets were normalized, the whole
        // slot grid was rebuilt in the foreign timezone, the forged grid
        // matched itself, and the naive column stored "11:30:00" verbatim —
        // the same slot booked twice.
        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toDateString().'T11:30:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'slot_unavailable');

        $this->assertSame(0, Appointment::withoutCabinetScope()
            ->where('booked_by_user_id', $second->getKey())
            ->count());
        $this->assertSame(1, Appointment::withoutCabinetScope()
            ->where('starts_at', $slot->format('Y-m-d H:i:s'))
            ->count());
    }

    public function test_booking_a_closed_month_is_rejected(): void
    {
        $slot = $this->bookableSlot();
        ['doctor' => $doctor] = $this->makeBookableClinic($slot, openMonth: false);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'slot_unavailable');
    }

    public function test_family_booking_with_an_active_dependent(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $member = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $patient->getKey(),
            'relation' => FamilyRelation::FATHER,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
        ]);

        $response = $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
            'family_member_id' => $member->getKey(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.booked_for.type', 'family')
            ->assertJsonPath('data.booked_for.family_member_id', $member->getKey())
            ->assertJsonPath('data.booked_for.name', 'Yacine Benali');

        // The dependent gets a dossier of its own, keyed by the family member,
        // reachable through the owner's phone.
        $this->assertDatabaseHas('patients', [
            'cabinet_id' => $cabinet->getKey(),
            'family_member_id' => $member->getKey(),
            'first_name' => 'Yacine',
            'phone' => $patient->phone,
            'family_group_public_id' => $patient->public_id,
            'family_relation' => FamilyRelation::FATHER->value,
            'family_contact_name' => trim(
                $patient->patientProfile->first_name.' '.$patient->patientProfile->last_name,
            ),
        ]);

        $this->assertDatabaseHas('appointments', [
            'cabinet_id' => $cabinet->getKey(),
            'booked_by_user_id' => $patient->getKey(),
            'family_member_id' => $member->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);
    }

    public function test_pending_linked_member_cannot_be_booked_for(): void
    {
        $slot = $this->bookableSlot();
        ['doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $pending = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $patient->getKey(),
        ]);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
            'family_member_id' => $pending->getKey(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'family_member_not_usable');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_another_accounts_family_member_cannot_be_booked_for(): void
    {
        $slot = $this->bookableSlot();
        ['doctor' => $doctor] = $this->makeBookableClinic($slot);

        $stranger = $this->makePatientUser();
        $foreignMember = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $stranger->getKey(),
        ]);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
            'family_member_id' => $foreignMember->getKey(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'family_member_not_usable');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_unlisted_doctor_is_not_bookable(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->update(['is_listed' => false]);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertStatus(404);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_my_prescriptions_lists_only_owned_dossiers(): void
    {
        $slot = $this->bookableSlot();
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        // Booking creates the account's dossier in this cabinet.
        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertStatus(201);

        $ownDossier = Patient::withoutCabinetScope()
            ->where('patient_user_id', $patient->getKey())
            ->firstOrFail();

        $strangerDossier = Patient::factory()->create(['cabinet_id' => $cabinet->getKey()]);

        foreach ([$ownDossier, $strangerDossier] as $dossier) {
            $prescription = new Prescription;
            $prescription->fill([
                'patient_id' => $dossier->getKey(),
                'prescribed_at' => CarbonImmutable::now()->subDay(),
                'items' => [['name' => 'Paracétamol 1g', 'dosage' => '3x/jour']],
                'notes' => 'Après les repas',
            ]);
            $prescription->setAttribute('cabinet_id', $cabinet->getKey());
            $prescription->save();
        }

        $this->getJson('/api/v1/my/prescriptions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient_display_name', $ownDossier->full_name)
            ->assertJsonPath('data.0.clinic.name', $cabinet->name)
            ->assertJsonPath('data.0.items.0.name', 'Paracétamol 1g');
    }

    public function test_index_and_show_return_only_the_callers_bookings(): void
    {
        $slot = $this->bookableSlot();
        ['doctor' => $doctor] = $this->makeBookableClinic($slot);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);

        $publicId = $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertStatus(201)->json('data.public_id');

        $this->getJson('/api/v1/my/appointments?scope=upcoming')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $publicId);

        $this->getJson('/api/v1/my/appointments/'.$publicId)
            ->assertStatus(200)
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonMissingPath('data.reception_notes');

        // Another patient can neither see it in their list nor address it by id.
        $other = $this->makePatientUser();
        Sanctum::actingAs($other, ['mobile']);

        $this->getJson('/api/v1/my/appointments')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/my/appointments/'.$publicId)
            ->assertStatus(404);
    }
}
