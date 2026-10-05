<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a prestation (acte) on the fly, from either the consultation
 * payment panel or the appointment booking dialog. Both capture a name and an
 * optional price, and both are reserved to the doctor: the assistant can take
 * payments and book visits but cannot invent new acts.
 */
class PrestationCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_doctor_can_add_a_consultation_prestation_with_name_and_price(): void
    {
        $doctor = $this->userWithRole(RoleName::DOCTOR);

        $this->actingAs($doctor)
            ->postJson(route('app.consultations.prestations.store'), [
                'name' => 'Échographie',
                'price' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('prestation.label', 'Échographie')
            ->assertJsonPath('prestation.amount', fn ($value): bool => (float) $value === 2500.0);

        $this->assertDatabaseHas('consultation_fees', [
            'label' => 'Échographie',
            'amount_minor' => 250000,
            'is_active' => true,
        ]);
    }

    public function test_doctor_can_add_a_consultation_prestation_without_a_price(): void
    {
        $doctor = $this->userWithRole(RoleName::DOCTOR);

        $this->actingAs($doctor)
            ->postJson(route('app.consultations.prestations.store'), [
                'name' => 'Contrôle',
            ])
            ->assertOk()
            ->assertJsonPath('prestation.label', 'Contrôle')
            ->assertJsonPath('prestation.amount', null);

        $this->assertDatabaseHas('consultation_fees', [
            'label' => 'Contrôle',
            'amount_minor' => null,
        ]);
    }

    public function test_consultation_prestation_requires_a_name(): void
    {
        $doctor = $this->userWithRole(RoleName::DOCTOR);

        $this->actingAs($doctor)
            ->postJson(route('app.consultations.prestations.store'), ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_assistant_cannot_add_a_consultation_prestation(): void
    {
        $assistant = $this->userWithRole(RoleName::ASSISTANT);

        $this->actingAs($assistant)
            ->postJson(route('app.consultations.prestations.store'), [
                'name' => 'Échographie',
                'price' => 2500,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('consultation_fees', ['label' => 'Échographie']);
    }

    public function test_doctor_can_add_an_appointment_prestation_with_a_price(): void
    {
        $doctor = $this->userWithRole(RoleName::DOCTOR);

        $this->actingAs($doctor)
            ->postJson(route('app.appointments.prestations.store'), [
                'name' => 'Pansement',
                'price' => 800,
            ])
            ->assertOk()
            ->assertJsonPath('prestation.label', 'Pansement')
            ->assertJsonPath('prestation.amount', fn ($value): bool => (float) $value === 800.0);

        $this->assertDatabaseHas('consultation_fees', [
            'label' => 'Pansement',
            'amount_minor' => 80000,
        ]);
    }

    public function test_assistant_cannot_add_an_appointment_prestation(): void
    {
        $assistant = $this->userWithRole(RoleName::ASSISTANT);

        $this->actingAs($assistant)
            ->postJson(route('app.appointments.prestations.store'), [
                'name' => 'Pansement',
                'price' => 800,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('consultation_fees', ['label' => 'Pansement']);
    }
}
