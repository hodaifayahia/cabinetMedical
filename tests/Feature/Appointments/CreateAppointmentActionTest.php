<?php

namespace Tests\Feature\Appointments;

use App\Actions\Appointments\CreateAppointmentAction;
use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateAppointmentActionTest extends TestCase
{
    use RefreshDatabase;

    protected CreateAppointmentAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->action = app(CreateAppointmentAction::class);
    }

    public function test_can_create_an_appointment_without_selecting_a_doctor(): void
    {
        $this->configureCabinetDoctor();
        $patient = Patient::factory()->create();
        $creator = $this->makeReceptionist();

        $appointment = $this->action->handle($patient, $creator, [
            'starts_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'ends_at' => CarbonImmutable::parse('2026-08-01 09:30:00'),
            'reason' => 'Annual follow-up visit',
            'reception_notes' => 'Patient requested the first morning slot.',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'patient_id' => $patient->id,
            'status' => AppointmentStatus::SCHEDULED->value,
            'created_by' => $creator->id,
        ]);

        $this->assertSame('2026-08-01', $appointment->appointment_date?->toDateString());
        $this->assertTrue($appointment->patient->is($patient));
    }

    public function test_appointments_table_has_no_doctor_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('appointments', 'doctor_id'),
            'A single-doctor cabinet must not store doctor_id on appointments.',
        );
    }

    public function test_invalid_time_ranges_are_rejected(): void
    {
        $this->configureCabinetDoctor();
        $patient = Patient::factory()->create();
        $creator = $this->makeReceptionist();

        try {
            $this->action->handle($patient, $creator, [
                'starts_at' => CarbonImmutable::parse('2026-08-01 10:00:00'),
                'ends_at' => CarbonImmutable::parse('2026-08-01 09:45:00'),
            ]);

            $this->fail('Expected a ValidationException for an invalid time range.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The appointment end time must be after the start time.',
                $exception->errors()['ends_at'][0],
            );
        }
    }

    public function test_overlapping_active_appointments_are_rejected(): void
    {
        $this->configureCabinetDoctor();
        $creator = $this->makeReceptionist();

        Appointment::factory()->confirmed()->create([
            'patient_id' => Patient::factory()->create()->id,
            'starts_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'ends_at' => CarbonImmutable::parse('2026-08-01 09:30:00'),
        ]);

        try {
            $this->action->handle(Patient::factory()->create(), $creator, [
                'starts_at' => CarbonImmutable::parse('2026-08-01 09:15:00'),
                'ends_at' => CarbonImmutable::parse('2026-08-01 09:45:00'),
            ]);

            $this->fail('Expected a ValidationException for an overlapping appointment.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'There is already an overlapping active appointment for the selected time range.',
                $exception->errors()['starts_at'][0],
            );
        }
    }

    public function test_cancelled_appointments_do_not_block_the_same_time_slot(): void
    {
        $this->configureCabinetDoctor();
        $creator = $this->makeReceptionist();

        Appointment::factory()->cancelled()->create([
            'patient_id' => Patient::factory()->create()->id,
            'starts_at' => CarbonImmutable::parse('2026-08-01 11:00:00'),
            'ends_at' => CarbonImmutable::parse('2026-08-01 11:30:00'),
        ]);

        $appointment = $this->action->handle(Patient::factory()->create(), $creator, [
            'starts_at' => CarbonImmutable::parse('2026-08-01 11:00:00'),
            'ends_at' => CarbonImmutable::parse('2026-08-01 11:30:00'),
        ]);

        $this->assertSame(AppointmentStatus::SCHEDULED, $appointment->status);
    }

    public function test_appointments_require_an_active_cabinet_doctor(): void
    {
        // An inactive profile means no active doctor is available for the cabinet.
        $this->configureCabinetDoctor(active: false);
        $patient = Patient::factory()->create();
        $creator = $this->makeReceptionist();

        try {
            $this->action->handle($patient, $creator, [
                'starts_at' => CarbonImmutable::parse('2026-08-01 13:00:00'),
                'ends_at' => CarbonImmutable::parse('2026-08-01 13:30:00'),
            ]);

            $this->fail('Expected a ValidationException when no active doctor is configured.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No active doctor is configured for the cabinet.',
                $exception->errors()['doctor'][0],
            );
        }
    }

    private function configureCabinetDoctor(bool $active = true): DoctorProfile
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::DOCTOR->value);

        return DoctorProfile::factory()
            ->for($user, 'user')
            ->when(! $active, fn ($factory) => $factory->inactive())
            ->create();
    }

    private function makeReceptionist(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::RECEPTIONIST->value);

        return $user;
    }
}
