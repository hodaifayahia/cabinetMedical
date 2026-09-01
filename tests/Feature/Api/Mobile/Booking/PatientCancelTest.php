<?php

namespace Tests\Feature\Api\Mobile\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * PATCH /api/v1/my/appointments/{publicId}/cancel — ownership, transition
 * guard rails, and the mobile cancellation cutoff
 * (config clinic.appointments.patient_cancel_cutoff_hours, default 2).
 */
class PatientCancelTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A mobile-booked appointment for the given patient account, with the
     * explicit tenancy columns a real booking would carry.
     *
     * @return array{appointment: Appointment, cabinet: Cabinet, owner: User}
     */
    private function makeBookedAppointment(
        User $patient,
        CarbonImmutable $startsAt,
        AppointmentStatus $status = AppointmentStatus::SCHEDULED,
    ): array {
        ['cabinet' => $cabinet, 'doctorUser' => $doctorUser] = $this->makeListedClinic();

        $dossier = Patient::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_user_id' => $patient->getKey(),
        ]);

        $appointment = Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => $dossier->getKey(),
            'booked_by_user_id' => $patient->getKey(),
            'booking_channel' => 'mobile_patient',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => $status,
        ]);

        return [
            'appointment' => $appointment,
            'cabinet' => $cabinet,
            'owner' => $doctorUser,
        ];
    }

    public function test_patient_cancels_before_the_cutoff(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addDays(3)->setTime(10, 0),
        );

        Sanctum::actingAs($patient, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel", [
            'cancellation_reason' => 'Empêchement personnel',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Empêchement personnel');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => 'cancelled',
            'cancelled_by' => $patient->getKey(),
            'cancellation_reason' => 'Empêchement personnel',
        ]);
    }

    public function test_cancelling_notifies_the_cabinet_owner(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment, 'owner' => $owner] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addDays(3)->setTime(10, 0),
        );

        Sanctum::actingAs($patient, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel")
            ->assertStatus(200);

        // The AppointmentNotificationObserver routes patient-made changes to
        // the cabinet owner as a database notification.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->getKey(),
        ]);
    }

    public function test_cancel_inside_the_cutoff_window_is_rejected(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addHour(),
        );

        Sanctum::actingAs($patient, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'cancel_cutoff_passed');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => 'scheduled',
            'cancelled_by' => null,
        ]);
    }

    public function test_completed_appointment_cannot_be_cancelled(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addDays(3)->setTime(10, 0),
            AppointmentStatus::COMPLETED,
        );

        Sanctum::actingAs($patient, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel")
            ->assertStatus(422);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => 'completed',
        ]);
    }

    public function test_cancelled_appointment_cannot_be_cancelled_again(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addDays(3)->setTime(10, 0),
            AppointmentStatus::CANCELLED,
        );

        Sanctum::actingAs($patient, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel")
            ->assertStatus(422);
    }

    public function test_patient_cannot_cancel_another_patients_appointment(): void
    {
        $patient = $this->makePatientUser();
        ['appointment' => $appointment] = $this->makeBookedAppointment(
            $patient,
            CarbonImmutable::now()->addDays(3)->setTime(10, 0),
        );

        $intruder = $this->makePatientUser();
        Sanctum::actingAs($intruder, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel")
            ->assertStatus(404);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => 'scheduled',
        ]);
    }
}
