<?php

namespace Tests\Feature\Api\Mobile\Boundaries;

use App\Enums\AppointmentStatus;
use App\Enums\FamilyMemberStatus;
use App\Models\Appointment;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Role boundary: one patient account can never reach another patient's data.
 * Foreign appointments answer 404 (their existence is never revealed), family
 * circles are invisible to strangers, and only the invited account may answer
 * a link request — never 200 across accounts.
 */
class PatientIsolationTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A fixed Tuesday morning keeps every relative date deterministic.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00'));
    }

    public function test_a_patient_cannot_see_another_patients_appointment(): void
    {
        $patientA = $this->makePatientUser();
        $patientB = $this->makePatientUser();
        $appointment = $this->makeBookedAppointment($patientB);

        // Control: the booking patient sees their own appointment.
        Sanctum::actingAs($patientB, ['mobile']);

        $this->getJson('/api/v1/my/appointments/'.$appointment->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $appointment->public_id);

        // The stranger gets a 404 — the public id must not even leak existence.
        Sanctum::actingAs($patientA, ['mobile']);

        $this->getJson('/api/v1/my/appointments/'.$appointment->public_id)
            ->assertNotFound();

        $this->getJson('/api/v1/my/appointments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_patient_cannot_cancel_another_patients_appointment(): void
    {
        $patientA = $this->makePatientUser();
        $patientB = $this->makePatientUser();
        $appointment = $this->makeBookedAppointment($patientB);

        Sanctum::actingAs($patientA, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel", [
            'cancellation_reason' => 'Tentative sur le compte d\'autrui.',
        ])->assertNotFound();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::SCHEDULED, $appointment->status);
        $this->assertNull($appointment->cancelled_at);

        // Control: the real owner can cancel (the slot is far outside the cutoff).
        Sanctum::actingAs($patientB, ['mobile']);

        $this->patchJson("/api/v1/my/appointments/{$appointment->public_id}/cancel", [
            'cancellation_reason' => 'Empêchement personnel.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_family_member_lists_are_isolated_between_accounts(): void
    {
        $patientA = $this->makePatientUser();
        $patientB = $this->makePatientUser();

        $foreignMember = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $patientB->getKey(),
        ]);

        Sanctum::actingAs($patientA, ['mobile']);

        $this->getJson('/api/v1/family-members')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Addressing the foreign member directly is forbidden, not fulfilled.
        $this->deleteJson('/api/v1/family-members/'.$foreignMember->getKey())
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        $this->assertDatabaseHas('family_members', ['id' => $foreignMember->getKey()]);
    }

    public function test_only_the_invited_account_can_answer_a_link_request(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $stranger = $this->makePatientUser();

        $member = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        // A stranger the request was never addressed to is rejected.
        Sanctum::actingAs($stranger, ['mobile']);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", [
            'action' => 'approve',
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        // The requesting owner cannot approve their own invitation either.
        Sanctum::actingAs($owner, ['mobile']);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", [
            'action' => 'approve',
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        $this->assertSame(FamilyMemberStatus::PENDING, $member->refresh()->status);

        // Control: the invited account itself may answer.
        Sanctum::actingAs($target, ['mobile']);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(FamilyMemberStatus::APPROVED, $member->refresh()->status);
    }

    /**
     * A listed clinic appointment booked from the given patient's account,
     * one week out — far beyond the cancellation cutoff.
     */
    private function makeBookedAppointment(User $bookedBy): Appointment
    {
        $clinic = $this->makeListedClinic();
        $startsAt = CarbonImmutable::parse('2026-10-13 09:00:00');

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
            'booked_by_user_id' => $bookedBy->getKey(),
            'booking_channel' => 'mobile_patient',
        ]);
    }
}
