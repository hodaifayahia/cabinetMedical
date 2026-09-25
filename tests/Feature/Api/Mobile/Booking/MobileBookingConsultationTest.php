<?php

namespace Tests\Feature\Api\Mobile\Booking;

use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Models\Wilaya;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

class MobileBookingConsultationTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    public function test_a_mobile_booking_can_be_checked_in_and_consulted_on_the_web(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Wilaya::factory()->create(['code' => 16]);

        $owner = app(CabinetProvisioningService::class)->provision([
            'name' => 'Dr Karim Boudjema',
            'email' => 'doctor@clinic.dz',
            'password' => 'mot-de-passe-solide-2026',
            'phone' => '0550000001',
            'cabinet_name' => 'Cabinet Test',
            'specialization' => 'Pédiatrie',
            'wilaya' => 16,
        ]);
        $cabinet = Cabinet::query()->withoutGlobalScopes()->findOrFail($owner->cabinet_id);
        app(CabinetFulfillmentService::class)->activate($cabinet);
        $doctor = DoctorProfile::withoutCabinetScope()->where('user_id', $owner->getKey())->firstOrFail();

        $slot = CarbonImmutable::now()->setTime(9, 0);
        DoctorSchedule::withoutCabinetScope()->where('doctor_id', $doctor->getKey())->delete();
        (new DoctorSchedule([
            'doctor_id' => $doctor->getKey(),
            'day_of_week' => Weekday::from((int) $slot->dayOfWeek)->value,
            'starts_at' => '00:00:00',
            'ends_at' => '23:30:00',
            'slot_duration' => 30,
            'is_active' => true,
        ]))->forceFill(['cabinet_id' => $cabinet->getKey()])->save();
        (new DoctorOpenMonth(['doctor_id' => $doctor->getKey(), 'year' => $slot->year, 'month' => $slot->month]))
            ->forceFill(['cabinet_id' => $cabinet->getKey(), 'is_open' => true])->save();

        $slot = CarbonImmutable::now()->addHour()->startOfHour();
        if (! $slot->isSameDay(CarbonImmutable::now())) {
            $this->markTestSkipped('Run before 23:00 so the slot is still today.');
        }

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient, ['mobile']);
        $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => $slot->toIso8601String(),
        ])->assertCreated();

        $appointment = Appointment::withoutCabinetScope()->latest('id')->firstOrFail();

        // The doctor, on the web, from here on.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail($owner->getKey()));

        $this->get('/app/appointments')->assertOk();

        // Today's list offers "patient arrived" first, since a mobile booking
        // arrives as scheduled and cannot be started before check-in.
        $this->get('/app/consultations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCheckIn', true)
                ->where('appointments.0.status', 'scheduled'));

        $this->post("/app/consultations/{$appointment->getKey()}/start")
            ->assertSessionHasErrors('status');

        $this->patch("/app/appointments/{$appointment->getKey()}/check-in")
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->post("/app/consultations/{$appointment->getKey()}/start")
            ->assertSessionHasNoErrors()->assertRedirect();

        $consultation = Consultation::withoutCabinetScope()->where('appointment_id', $appointment->getKey())->firstOrFail();

        $this->get("/app/consultations/{$consultation->getKey()}")->assertOk();
    }
}
