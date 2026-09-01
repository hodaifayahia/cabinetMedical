<?php

namespace Tests\Feature\Api\Mobile\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\Mobile\AppointmentStatusChanged;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Staff mobile agenda actions: today list, decline, reschedule, no-show.
 * Every forbidden path must answer 403/404 — never 200.
 */
class StaffAppointmentActionsTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A fixed Tuesday morning keeps every relative date deterministic.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00'));
    }

    public function test_today_lists_only_the_cabinets_appointments_for_the_requested_date(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();
        $startsAt = CarbonImmutable::parse('2026-10-07 10:00:00');

        $mine = $this->makeAppointment($clinicA, $startsAt);
        $other = $this->makeAppointment($clinicB, $startsAt);
        $this->makeAppointment($clinicA, $startsAt->addDay());

        Sanctum::actingAs($clinicA['doctorUser']);

        $response = $this->getJson('/api/v1/mobile/appointments/today?date=2026-10-07')
            ->assertOk();

        $publicIds = collect($response->json('data'))->pluck('public_id');

        $this->assertCount(1, $publicIds);
        $this->assertTrue($publicIds->contains($mine->public_id));
        $this->assertFalse($publicIds->contains($other->public_id));

        // Staff may see reception notes — the staff resource is reused as-is.
        $this->assertArrayHasKey('reception_notes', $response->json('data.0'));
    }

    public function test_decline_cancels_the_appointment_and_notifies_the_booking_patient(): void
    {
        $clinic = $this->makeBookableClinic();
        $patientUser = $this->makePatientUser();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'), bookedBy: $patientUser);

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/decline", [
            'reason' => 'Médecin absent ce jour-là.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Médecin absent ce jour-là.');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::CANCELLED, $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertSame($clinic['doctorUser']->getKey(), $appointment->cancelled_by);

        $notification = $patientUser->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(AppointmentStatusChanged::class, $notification->type);
        $this->assertSame('cancelled', $notification->data['status']);
        $this->assertSame('doctor', $notification->data['changed_by_role']);
    }

    public function test_decline_requires_a_reason(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'));

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/decline", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_decline_is_rejected_once_the_appointment_is_terminal(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment(
            $clinic,
            CarbonImmutable::parse('2026-10-13 09:00:00'),
            status: AppointmentStatus::COMPLETED,
        );

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/decline", [
            'reason' => 'Trop tard.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(AppointmentStatus::COMPLETED, $appointment->refresh()->status);
    }

    public function test_no_show_marks_a_checked_in_appointment(): void
    {
        $clinic = $this->makeBookableClinic();
        $patientUser = $this->makePatientUser();
        $appointment = $this->makeAppointment(
            $clinic,
            CarbonImmutable::parse('2026-10-13 09:00:00'),
            status: AppointmentStatus::CHECKED_IN,
            bookedBy: $patientUser,
        );

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');

        $this->assertSame(AppointmentStatus::NO_SHOW, $appointment->refresh()->status);

        // The status change reaches the booking patient through the observer.
        $notification = $patientUser->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('no_show', $notification->data['status']);
    }

    public function test_no_show_is_rejected_for_a_cancelled_appointment(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment(
            $clinic,
            CarbonImmutable::parse('2026-10-13 09:00:00'),
            status: AppointmentStatus::CANCELLED,
        );

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/no-show")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_reschedule_moves_the_appointment_and_notifies_the_booking_patient(): void
    {
        $clinic = $this->makeBookableClinic();
        $patientUser = $this->makePatientUser();
        $appointment = $this->makeAppointment(
            $clinic,
            CarbonImmutable::parse('2026-10-13 09:00:00'),
            bookedBy: $patientUser,
        );

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-20 10:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $appointment->refresh();
        $this->assertSame('2026-10-20 10:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20 10:30', $appointment->ends_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20', $appointment->appointment_date->toDateString());

        // The status did not change, so the controller (not the observer)
        // tells the booking patient about the new time.
        $notification = $patientUser->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(AppointmentStatusChanged::class, $notification->type);
        $this->assertStringContainsString('10:00', $notification->data['starts_at']);
    }

    public function test_reschedule_to_an_occupied_slot_is_a_conflict(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'));
        $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 10:00:00'));

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-13 10:00:00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'slot_unavailable');

        $this->assertSame('09:00', $appointment->refresh()->starts_at->format('H:i'));

        // Its own current block is ignored: nudging onto itself stays legal.
        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-13 09:00:00',
        ])->assertOk();
    }

    public function test_reschedule_normalizes_a_utc_offset_to_local_time(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'));

        Sanctum::actingAs($clinic['doctorUser']);

        // 09:00Z == 10:00 Africa/Algiers: an honest UTC-serializing client
        // must land on — and store — the 10:00 local slot.
        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-20T09:00:00Z',
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame('2026-10-20 10:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20 10:30', $appointment->ends_at->format('Y-m-d H:i'));
    }

    public function test_reschedule_rejects_a_past_start_time(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'));

        Sanctum::actingAs($clinic['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-01 10:00:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_patient_token_is_rejected_on_staff_appointment_endpoints(): void
    {
        $clinic = $this->makeBookableClinic();
        $appointment = $this->makeAppointment($clinic, CarbonImmutable::parse('2026-10-13 09:00:00'));

        Sanctum::actingAs($this->makePatientUser());

        $this->getJson('/api/v1/mobile/appointments/today')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_token_forbidden');

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/decline", ['reason' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_token_forbidden');
    }

    public function test_staff_of_another_cabinet_cannot_touch_the_appointment(): void
    {
        $clinicA = $this->makeBookableClinic();
        $clinicB = $this->makeListedClinic();
        $appointment = $this->makeAppointment($clinicA, CarbonImmutable::parse('2026-10-13 09:00:00'));

        Sanctum::actingAs($clinicB['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/decline", ['reason' => 'x'])
            ->assertNotFound();

        $this->patchJson("/api/v1/mobile/appointments/{$appointment->getKey()}/reschedule", [
            'starts_at' => '2026-10-13 10:00:00',
        ])->assertNotFound();

        $publicIds = collect(
            $this->getJson('/api/v1/mobile/appointments/today?date=2026-10-13')
                ->assertOk()
                ->json('data'),
        )->pluck('public_id');

        $this->assertFalse($publicIds->contains($appointment->public_id));
        $this->assertSame(AppointmentStatus::SCHEDULED, $appointment->refresh()->status);
    }

    /**
     * A listed clinic whose doctor works Tuesdays 09:00–17:00 (30-minute
     * slots) with October 2026 open for booking.
     *
     * @return array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}
     */
    private function makeBookableClinic(): array
    {
        $clinic = $this->makeListedClinic();

        DoctorSchedule::factory()->create([
            'doctor_id' => $clinic['doctor']->getKey(),
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'day_of_week' => Weekday::TUESDAY,
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
            'slot_duration' => 30,
            'is_active' => true,
        ]);

        DoctorOpenMonth::factory()->create([
            'doctor_id' => $clinic['doctor']->getKey(),
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'year' => 2026,
            'month' => 10,
            'is_open' => true,
        ]);

        return $clinic;
    }

    /**
     * @param  array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}  $clinic
     */
    private function makeAppointment(
        array $clinic,
        CarbonImmutable $startsAt,
        AppointmentStatus $status = AppointmentStatus::SCHEDULED,
        ?User $bookedBy = null,
    ): Appointment {
        $patient = Patient::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
        ]);

        return Appointment::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'patient_id' => $patient->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => $status,
            'booked_by_user_id' => $bookedBy?->getKey(),
            'booking_channel' => $bookedBy === null ? null : 'mobile_patient',
        ]);
    }
}
