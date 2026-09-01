<?php

namespace Tests\Feature\Appointments;

use App\Enums\FamilyRelation;
use App\Enums\RoleName;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\PatientProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Where a human finally sees the provenance the sync layer carries.
 *
 * The doctor's desktop must be able to say "a mother booked this from the app
 * for her son, call her on this number" — whether the appointment was booked
 * on this installation (live foreign keys) or arrived from the hosted one
 * (foreign keys null, `booking_context` populated).
 */
class BookingProvenanceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_resource_surfaces_a_mobile_family_booking_from_the_live_relations(): void
    {
        $this->actingAs($this->doctor());
        $account = $this->bookingAccount();

        $appointment = $this->appointmentBookedWith([
            'booking_channel' => 'mobile_patient',
            'booked_by_user_id' => $account->getKey(),
            'family_member_id' => FamilyMember::factory()->create([
                'owner_user_id' => $account->getKey(),
                'relation' => FamilyRelation::SON,
                'first_name' => 'Yacine',
                'last_name' => 'Benali',
            ])->getKey(),
        ]);

        $this->assertSame(
            [
                'channel' => 'mobile_patient',
                'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
            ],
            $this->staffBooking($appointment),
        );
    }

    public function test_staff_resource_reads_an_imported_appointment_from_its_stored_context(): void
    {
        $this->actingAs($this->doctor());

        // The importer never writes the foreign keys — they belong to the
        // sending installation — so this is all a desktop ever has.
        $appointment = $this->appointmentBookedWith([
            'booking_context' => [
                'channel' => 'mobile_patient',
                'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
            ],
        ]);

        $this->assertNull($appointment->booked_by_user_id);
        $this->assertNull($appointment->family_member_id);
        $this->assertSame(
            [
                'channel' => 'mobile_patient',
                'booked_for' => ['type' => 'family', 'name' => 'Yacine Benali', 'relation' => 'son'],
                'booked_by' => ['name' => 'Amine Benali', 'phone' => '0660000001'],
            ],
            $this->staffBooking($appointment),
        );
    }

    public function test_staff_resource_exposes_no_booking_foreign_key_and_stays_null_for_reception_bookings(): void
    {
        $this->actingAs($this->doctor());
        $account = $this->bookingAccount();

        $mobile = $this->appointmentBookedWith([
            'booking_channel' => 'mobile_patient',
            'booked_by_user_id' => $account->getKey(),
        ]);

        $serialised = json_encode(
            (new AppointmentResource($mobile->load('patient')))
                ->toArray(Request::create('/api/v1/appointments')),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString('booked_by_user_id', $serialised);
        $this->assertStringNotContainsString('family_member_id', $serialised);

        // Reception's own bookings have no provenance to show.
        $this->assertNull($this->staffBooking($this->appointmentBookedWith([])));
    }

    public function test_the_appointment_book_carries_one_provenance_line_per_row(): void
    {
        $doctor = $this->doctor();
        $account = $this->bookingAccount();
        $startsAt = CarbonImmutable::today()->setTime(9, 0);

        $this->appointmentBookedWith([
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'booking_channel' => 'mobile_patient',
            'booked_by_user_id' => $account->getKey(),
            'family_member_id' => FamilyMember::factory()->create([
                'owner_user_id' => $account->getKey(),
                'relation' => FamilyRelation::SON,
                'first_name' => 'Yacine',
                'last_name' => 'Benali',
            ])->getKey(),
        ]);

        $this->actingAs($doctor)
            ->get(route('app.appointments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.data.0.booking.channel', 'mobile_patient')
                ->where('appointments.data.0.booking.booked_for.type', 'family')
                ->where('appointments.data.0.booking.booked_for.relation', 'son')
                ->where('appointments.data.0.booking.booked_by.name', 'Amine Benali')
                ->where('appointments.data.0.booking.booked_by.phone', '0660000001'),
            );
    }

    private function doctor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::DOCTOR->value);

        return $user;
    }

    /**
     * The mobile account a booking is made from: Amine, with the demographic
     * profile the app collected and the number reception would call.
     */
    private function bookingAccount(): User
    {
        $user = User::factory()->create([
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
     * An appointment for Yacine, whose booking columns are written outside
     * mass assignment exactly as the booking service and importer write them.
     *
     * @param  array<string, mixed>  $booking
     */
    private function appointmentBookedWith(array $booking): Appointment
    {
        $appointment = Appointment::factory()->create([
            'patient_id' => Patient::factory()->create([
                'first_name' => 'Yacine',
                'last_name' => 'Benali',
            ])->getKey(),
        ]);

        if ($booking !== []) {
            $appointment->forceFill($booking)->saveQuietly();
        }

        /** @var Appointment $fresh */
        $fresh = $appointment->fresh();

        return $fresh;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffBooking(Appointment $appointment): ?array
    {
        $booking = (new AppointmentResource($appointment))
            ->toArray(Request::create('/api/v1/appointments'))['booking'];

        return is_array($booking) ? $booking : null;
    }
}
