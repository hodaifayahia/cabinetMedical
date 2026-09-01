<?php

namespace Tests\Feature\Api\Mobile\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\Mobile\AppointmentStatusChanged;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * AppointmentNotificationObserver: a status change writes a database
 * notification to the right party, coexists with the appointment sync
 * hooks, and never breaks the saving transaction.
 */
class AppointmentStatusNotificationTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_staff_status_change_writes_a_database_notification_to_the_booking_patient(): void
    {
        [$cabinet, $doctorUser, $patientUser, $appointment] = $this->makeMobileBookedAppointment();

        $this->actingAs($doctorUser);
        $appointment->update([
            'status' => AppointmentStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $patientUser->getKey(),
            'type' => AppointmentStatusChanged::class,
        ]);

        $data = $patientUser->notifications()->firstOrFail()->data;
        $this->assertSame($appointment->public_id, $data['appointment_public_id']);
        $this->assertSame(AppointmentStatus::CONFIRMED->value, $data['status']);
        $this->assertSame($cabinet->name, $data['clinic_name']);
        $this->assertSame($doctorUser->name, $data['doctor_name']);
        $this->assertSame('doctor', $data['changed_by_role']);
        $this->assertNotNull($data['starts_at']);

        // The doctor acted, so nothing lands in the clinic's own inbox.
        $this->assertSame(0, $doctorUser->notifications()->count());

        // The heavy sync hooks on the model still ran untouched.
        $this->assertSame(2, $appointment->fresh()->sync_version);
        $this->assertDatabaseHas('appointment_sync_events', [
            'appointment_id' => $appointment->getKey(),
            'version' => 2,
        ]);
    }

    public function test_a_patient_made_status_change_notifies_the_cabinet_owner(): void
    {
        [, $doctorUser, $patientUser, $appointment] = $this->makeMobileBookedAppointment();

        $this->actingAs($patientUser);
        $appointment->update([
            'status' => AppointmentStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $patientUser->getKey(),
            'cancellation_reason' => 'Empêchement personnel.',
        ]);

        // The owner (doctor) is told; the acting patient is not notified.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $doctorUser->getKey(),
            'type' => AppointmentStatusChanged::class,
        ]);
        $this->assertSame(0, $patientUser->notifications()->count());

        $data = $doctorUser->notifications()->firstOrFail()->data;
        $this->assertSame(AppointmentStatus::CANCELLED->value, $data['status']);
        $this->assertSame('patient', $data['changed_by_role']);
    }

    public function test_an_unauthenticated_system_change_still_notifies_the_booking_patient(): void
    {
        [, , $patientUser, $appointment] = $this->makeMobileBookedAppointment();

        $appointment->update([
            'status' => AppointmentStatus::NO_SHOW,
        ]);

        $data = $patientUser->notifications()->firstOrFail()->data;
        $this->assertSame(AppointmentStatus::NO_SHOW->value, $data['status']);
        $this->assertSame('system', $data['changed_by_role']);
    }

    public function test_no_notification_when_the_appointment_was_not_booked_through_mobile(): void
    {
        ['cabinet' => $cabinet, 'doctorUser' => $doctorUser] = $this->makeListedClinic();
        $patient = Patient::factory()->create(['cabinet_id' => $cabinet->getKey()]);
        $appointment = Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => $patient->getKey(),
        ]);

        $this->actingAs($doctorUser);
        $appointment->update([
            'status' => AppointmentStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_no_notification_when_the_update_does_not_change_the_status(): void
    {
        [, $doctorUser, , $appointment] = $this->makeMobileBookedAppointment();

        $this->actingAs($doctorUser);
        $appointment->update(['reception_notes' => 'Rien à signaler.']);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_failing_notification_never_blocks_the_status_change(): void
    {
        [, $doctorUser, , $appointment] = $this->makeMobileBookedAppointment();

        // Make the notification insert impossible: the observer must swallow
        // the failure and let the appointment save regardless.
        Schema::drop('notifications');

        $this->actingAs($doctorUser);
        $appointment->update([
            'status' => AppointmentStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $this->assertSame(AppointmentStatus::CONFIRMED, $appointment->fresh()->status);
    }

    /**
     * An appointment booked through the mobile channel in a listed clinic.
     *
     * @return array{0: Cabinet, 1: User, 2: User, 3: Appointment}
     */
    private function makeMobileBookedAppointment(): array
    {
        ['cabinet' => $cabinet, 'doctorUser' => $doctorUser] = $this->makeListedClinic();
        $patientUser = $this->makePatientUser();

        $patient = Patient::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_user_id' => $patientUser->getKey(),
        ]);

        $appointment = Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => $patient->getKey(),
            'booked_by_user_id' => $patientUser->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);

        // Sanity: the helper clinic exposes an active doctor profile the
        // observer resolves the doctor name from.
        $this->assertNotNull(
            DoctorProfile::withoutCabinetScope()
                ->where('cabinet_id', $cabinet->getKey())
                ->where('is_active', true)
                ->first(),
        );

        return [$cabinet, $doctorUser, $patientUser, $appointment];
    }
}
