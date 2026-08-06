<?php

namespace Tests\Feature\Encounters;

use App\Enums\EncounterStatus;
use App\Enums\RoleName;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EncounterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $roleName = match ($role) {
            'DOCTOR' => RoleName::DOCTOR,
            'RECEPTIONIST' => RoleName::RECEPTIONIST,
            'CASHIER' => RoleName::CASHIER,
        };

        $user = User::factory()->create();
        $user->assignRole($roleName->value);

        return $user;
    }

    // Index Tests

    public function test_authorized_user_can_view_patient_encounters(): void
    {
        $patient = Patient::factory()->create();
        Encounter::factory(3)->for($patient)->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.view');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('encounters/Index')
            ->has('encounters.data', 3));
    }

    public function test_unauthorized_user_cannot_view_encounters(): void
    {
        $patient = Patient::factory()->create();
        $user = $this->userWithRole('CASHIER');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters");

        $response->assertForbidden();
    }

    // Create Tests

    public function test_authorized_user_can_view_create_form(): void
    {
        $patient = Patient::factory()->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.create');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/create");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('encounters/Create'));
    }

    public function test_user_cannot_create_encounter_without_permission(): void
    {
        $patient = Patient::factory()->create();
        $user = $this->userWithRole('RECEPTIONIST');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters", [
                'occurred_at' => now()->toDateString(),
            ]);

        $response->assertForbidden();
    }

    public function test_user_can_create_new_encounter(): void
    {
        $patient = Patient::factory()->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.create');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters", [
                'occurred_at' => now()->toDateString(),
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('encounters', [
            'patient_id' => $patient->id,
            'provider_id' => $user->id,
            'status' => EncounterStatus::Draft->value,
        ]);
    }

    public function test_encounter_creation_validates_occurred_at(): void
    {
        $patient = Patient::factory()->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.create');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters", [
                'occurred_at' => now()->addDay()->toDateString(),
            ]);

        $response->assertSessionHasErrors('occurred_at');
    }

    // Show Tests

    public function test_authorized_user_can_view_encounter(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()->for($patient)->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.view');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/{$encounter->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('encounters/Show')
            ->where('encounter.id', $encounter->id));
    }

    public function test_user_cannot_view_encounter_without_permission(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()->for($patient)->create();
        $user = $this->userWithRole('CASHIER');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/{$encounter->id}");

        $response->assertForbidden();
    }

    // Edit Tests

    public function test_authorized_user_can_view_edit_form(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::Draft])
            ->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.update');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/{$encounter->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('encounters/Edit'));
    }

    public function test_user_cannot_edit_signed_encounter(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
                'signed_at' => now(),
            ])
            ->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.update');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/{$encounter->id}/edit");

        $response->assertForbidden();
    }

    // Update (Save Draft) Tests

    public function test_user_can_save_encounter_notes(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::Draft])
            ->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.update');

        $response = $this->actingAs($user)
            ->put("/app/patients/{$patient->id}/encounters/{$encounter->id}", [
                'reason_for_visit' => 'Fever and cough',
                'clinical_examination' => 'Temp 38.5C, lungs clear',
                'diagnosis_assessment' => 'Viral infection',
                'treatment_plan' => 'Rest and fluids',
                'lock_version' => $encounter->lock_version,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('encounter_notes', [
            'encounter_id' => $encounter->id,
            'section' => 'reason_for_visit',
            'content_text' => 'Fever and cough',
        ]);
    }

    public function test_optimistic_lock_prevents_conflicting_updates(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::Draft, 'lock_version' => 2])
            ->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.update');

        $response = $this->actingAs($user)
            ->put("/app/patients/{$patient->id}/encounters/{$encounter->id}", [
                'reason_for_visit' => 'New content',
                'lock_version' => 1, // Outdated version
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_cannot_save_notes_to_signed_encounter(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
            ])
            ->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.update');

        $response = $this->actingAs($user)
            ->put("/app/patients/{$patient->id}/encounters/{$encounter->id}", [
                'reason_for_visit' => 'Attempted change',
                'lock_version' => $encounter->lock_version,
            ]);

        $response->assertForbidden();
    }

    // Sign Tests

    public function test_authorized_user_can_sign_encounter(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::InProgress])
            ->create();
        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.sign');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/sign");

        $response->assertRedirect();
        $encounter->refresh();
        $this->assertTrue($encounter->isSigned());
        $this->assertEquals($user->id, $encounter->signed_by);
        $this->assertNotNull($encounter->signed_at);
        $this->assertNotNull($encounter->content_hash);
    }

    public function test_user_cannot_sign_without_permission(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::InProgress])
            ->create();
        $user = $this->userWithRole('RECEPTIONIST');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/sign");

        $response->assertForbidden();
    }

    public function test_cannot_sign_already_signed_encounter(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
                'signed_at' => now(),
            ])
            ->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.sign');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/sign");

        $response->assertSessionHasErrors();
    }

    // Amendment Tests

    public function test_authorized_user_can_view_amendment_form(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
                'signed_at' => now(),
            ])
            ->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.amend');

        $response = $this->actingAs($user)
            ->get("/app/patients/{$patient->id}/encounters/{$encounter->id}/amend");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('encounters/CreateAmendment'));
    }

    public function test_user_cannot_amend_unsigned_encounter(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()
            ->for($patient)
            ->state(['status' => EncounterStatus::Draft])
            ->create();

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.amend');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/amend", [
                'amendment_reason' => 'Correction needed',
            ]);

        $response->assertForbidden();
    }

    public function test_user_can_create_amendment_to_signed_encounter(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
                'signed_at' => now(),
            ])
            ->create();

        // Create original notes
        $encounter->notes()->create([
            'section' => 'reason_for_visit',
            'content_text' => 'Fever',
            'author_id' => $signer->id,
            'revision_number' => 1,
        ]);

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.amend');

        $response = $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/amend", [
                'amendment_reason' => 'Added missing allergy info',
                'reason_for_visit' => 'Fever and rash (corrected)',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('encounters', [
            'amends_encounter_id' => $encounter->id,
            'amendment_reason' => 'Added missing allergy info',
            'status' => EncounterStatus::Draft->value,
        ]);
    }

    public function test_amendment_starts_in_draft_state(): void
    {
        $patient = Patient::factory()->create();
        $signer = $this->userWithRole('DOCTOR');
        $encounter = Encounter::factory()
            ->for($patient)
            ->state([
                'status' => EncounterStatus::Signed,
                'signed_by' => $signer->id,
                'signed_at' => now(),
            ])
            ->create();

        $encounter->notes()->create([
            'section' => 'reason_for_visit',
            'content_text' => 'Fever',
            'author_id' => $signer->id,
            'revision_number' => 1,
        ]);

        $user = $this->userWithRole('DOCTOR');
        $user->givePermissionTo('encounters.amend');

        $this->actingAs($user)
            ->post("/app/patients/{$patient->id}/encounters/{$encounter->id}/amend", [
                'amendment_reason' => 'Correction',
            ]);

        $amendment = Encounter::where('amends_encounter_id', $encounter->id)->first();
        $this->assertEquals(EncounterStatus::Draft, $amendment->status);
    }
}
