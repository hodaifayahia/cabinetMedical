<?php

namespace Tests\Feature\Patients;

use App\Enums\RoleName;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientControllerTest extends TestCase
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

    public function test_authorized_user_can_view_the_patient_index(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);

        Patient::factory()->count(3)->create();

        $this->actingAs($user)
            ->get(route('app.patients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patients/Index')
                ->has('patients.data', 3)
                ->where('patients.total', 3),
            );
    }

    public function test_index_is_forbidden_without_view_permission(): void
    {
        $user = $this->userWithRole(RoleName::STOCK_MANAGER);

        $this->actingAs($user)
            ->get(route('app.patients.index'))
            ->assertForbidden();
    }

    public function test_index_can_be_filtered_by_search_term(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);

        Patient::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        Patient::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->actingAs($user)
            ->get(route('app.patients.index', ['search' => 'Lovelace']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patients/Index')
                ->has('patients.data', 1)
                ->where('patients.data.0.full_name', 'Ada Lovelace'),
            );
    }

    public function test_create_page_is_rendered_for_authorized_user(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);

        $this->actingAs($user)
            ->get(route('app.patients.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patients/Create')
                ->where('genders', [
                    ['value' => 'male', 'label' => 'Homme'],
                    ['value' => 'female', 'label' => 'Femme'],
                    ['value' => 'other', 'label' => 'Autre'],
                    ['value' => 'undisclosed', 'label' => 'Non renseigné'],
                ])
                ->has('bloodGroups'),
            );
    }

    public function test_authorized_user_can_store_a_patient(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);

        $response = $this->actingAs($user)->post(route('app.patients.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'gender' => 'female',
            'phone' => '0555123456',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'gender' => 'female',
            'created_by' => $user->id,
        ]);
    }

    public function test_store_requires_first_and_last_name(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);

        $this->actingAs($user)
            ->from(route('app.patients.create'))
            ->post(route('app.patients.store'), ['first_name' => '', 'last_name' => ''])
            ->assertSessionHasErrors(['first_name', 'last_name'])
            ->assertRedirect(route('app.patients.create'));
    }

    public function test_store_is_forbidden_without_create_permission(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);

        $this->actingAs($user)
            ->post(route('app.patients.store'), [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('patients', ['last_name' => 'Lovelace']);
    }

    public function test_authorized_user_can_view_a_patient(): void
    {
        $user = $this->userWithRole(RoleName::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->actingAs($user)
            ->get(route('app.patients.show', $patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patients/Show')
                ->where('patient.id', $patient->id),
            );
    }

    public function test_authorized_user_can_update_a_patient(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);
        $patient = Patient::factory()->create(['city' => 'Algiers']);

        $response = $this->actingAs($user)->put(route('app.patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'city' => 'Oran',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('app.patients.show', $patient));

        $this->assertSame('Oran', $patient->refresh()->city);
    }

    public function test_update_is_forbidden_without_update_permission(): void
    {
        $user = $this->userWithRole(RoleName::CASHIER);
        $patient = Patient::factory()->create(['city' => 'Algiers']);

        $this->actingAs($user)
            ->put(route('app.patients.update', $patient), [
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'city' => 'Oran',
            ])
            ->assertForbidden();

        $this->assertSame('Algiers', $patient->refresh()->city);
    }

    public function test_patient_archiving_route_is_not_available(): void
    {
        $user = $this->userWithRole(RoleName::ADMINISTRATOR);
        $patient = Patient::factory()->create();

        $this->actingAs($user)
            ->delete("/app/patients/{$patient->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    }
}
