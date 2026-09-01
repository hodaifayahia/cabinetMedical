<?php

namespace Tests\Feature\Api\Mobile\Boundaries;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Role boundary: reception (Assistant role) is welded to exactly one cabinet.
 * Another cabinet's appointments are invisible (404) and untouchable through
 * both the staff API and the staff mobile endpoints — never 200.
 */
class ReceptionScopedToOneCabinetTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A fixed Tuesday morning keeps every relative date deterministic.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00'));
    }

    public function test_reading_another_cabinets_appointment_is_a_404(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();
        $assistant = $this->makeAssistant($clinicA);

        $mine = $this->makeAppointment($clinicA, CarbonImmutable::parse('2026-10-07 09:00:00'));
        $other = $this->makeAppointment($clinicB, CarbonImmutable::parse('2026-10-07 09:00:00'));

        Sanctum::actingAs($assistant);

        // Control: the assistant can read their own cabinet's appointment...
        $this->getJson('/api/v1/appointments/'.$mine->getKey())->assertOk();

        // ...but cabinet B's row does not exist for them — by id or public id.
        $this->getJson('/api/v1/appointments/'.$other->getKey())->assertNotFound();
        $this->getJson('/api/v1/appointments/'.$other->public_id)->assertNotFound();
    }

    public function test_declining_another_cabinets_appointment_is_out_of_reach(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();
        $assistant = $this->makeAssistant($clinicA);

        $other = $this->makeAppointment($clinicB, CarbonImmutable::parse('2026-10-07 09:00:00'));

        Sanctum::actingAs($assistant);

        $this->patchJson("/api/v1/mobile/appointments/{$other->getKey()}/decline", [
            'reason' => 'Tentative inter-cabinet.',
        ])->assertNotFound();

        $other->refresh();
        $this->assertSame(AppointmentStatus::SCHEDULED, $other->status);
        $this->assertNull($other->cancelled_at);

        // Control: the same action inside their own cabinet succeeds, so the
        // 404 above is scoping — not a missing permission.
        $mine = $this->makeAppointment($clinicA, CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->patchJson("/api/v1/mobile/appointments/{$mine->getKey()}/decline", [
            'reason' => 'Fermeture exceptionnelle.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_the_today_list_never_contains_another_cabinets_rows(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();
        $assistant = $this->makeAssistant($clinicA);

        $mine = $this->makeAppointment($clinicA, CarbonImmutable::parse('2026-10-07 10:00:00'));
        $other = $this->makeAppointment($clinicB, CarbonImmutable::parse('2026-10-07 10:00:00'));

        Sanctum::actingAs($assistant);

        $publicIds = collect(
            $this->getJson('/api/v1/mobile/appointments/today?date=2026-10-07')
                ->assertOk()
                ->json('data'),
        )->pluck('public_id');

        $this->assertCount(1, $publicIds);
        $this->assertTrue($publicIds->contains($mine->public_id));
        $this->assertFalse($publicIds->contains($other->public_id));
    }

    /**
     * An approved reception account attached to the clinic's cabinet.
     *
     * @param  array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}  $clinic
     */
    private function makeAssistant(array $clinic): User
    {
        $assistant = User::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        return $assistant;
    }

    /**
     * @param  array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}  $clinic
     */
    private function makeAppointment(array $clinic, CarbonImmutable $startsAt): Appointment
    {
        $patient = Patient::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
        ]);

        return Appointment::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'patient_id' => $patient->getKey(),
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => AppointmentStatus::SCHEDULED,
        ]);
    }
}
