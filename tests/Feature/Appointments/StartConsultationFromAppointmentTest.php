<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StartConsultationFromAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function doctor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::DOCTOR->value);

        return $user;
    }

    public function test_appointment_list_only_flags_checked_in_appointments_as_startable(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();
        $startsAt = CarbonImmutable::today()->setTime(9, 0);
        Appointment::factory()->for($patient)->create([
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'status' => AppointmentStatus::SCHEDULED,
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get(route('app.appointments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.data.0.can_start', false)
                ->where('appointments.data.0.consultation_id', null)
                ->where('permissions.startConsultation', true),
            );
    }

    public function test_starting_consultation_creates_encounter_linked_to_appointment(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();
        $startsAt = CarbonImmutable::today()->setTime(10, 0);
        $appointment = Appointment::factory()->for($patient)->create([
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'status' => AppointmentStatus::CHECKED_IN,
            'created_by' => $doctor->id,
        ]);

        $response = $this->actingAs($doctor)
            ->post(route('app.consultations.start', $appointment));

        $consultation = Consultation::query()
            ->where('appointment_id', $appointment->id)
            ->sole();

        $response->assertRedirect(route('app.consultations.show', $consultation));

        $this->assertSame($patient->id, $consultation->patient_id);
        $this->assertSame($appointment->id, $consultation->appointment_id);
        $this->assertSame('in_progress', $consultation->status);
        $this->assertSame($doctor->id, $consultation->created_by);

        // The appointment transitions into the in-consultation state.
        $this->assertSame(
            AppointmentStatus::IN_PROGRESS,
            $appointment->fresh()->status,
        );
    }

    public function test_starting_consultation_twice_reuses_the_same_consultation(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();
        $startsAt = CarbonImmutable::today()->setTime(11, 0);
        $appointment = Appointment::factory()->for($patient)->create([
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'status' => AppointmentStatus::CHECKED_IN,
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)->post(route('app.consultations.start', $appointment));
        $this->actingAs($doctor)->post(route('app.consultations.start', $appointment));

        $this->assertSame(
            1,
            Consultation::query()->where('appointment_id', $appointment->id)->count(),
        );
    }

    public function test_start_endpoint_requires_the_consultations_create_permission(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole(RoleName::RECEPTIONIST->value);

        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($patient)->create([
            'created_by' => $receptionist->id,
        ]);

        $this->actingAs($receptionist)
            ->post(route('app.consultations.start', $appointment))
            ->assertForbidden();

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_start_endpoint_requires_the_patient_to_be_checked_in(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($patient)->create([
            'status' => AppointmentStatus::CONFIRMED,
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->post(route('app.consultations.start', $appointment))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('consultations', 0);
    }
}
