<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeCabinetWithUser(string $email): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        $user = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole(RoleName::ADMINISTRATOR->value);

        return [$cabinet, $user];
    }

    public function test_users_only_see_their_own_cabinet_patients(): void
    {
        [$cabinetA, $userA] = $this->makeCabinetWithUser('a@example.com');
        [$cabinetB, $userB] = $this->makeCabinetWithUser('b@example.com');

        // Create one patient in each cabinet by acting as each owner.
        $this->actingAs($userA);
        $patientA = Patient::factory()->create(['first_name' => 'Alice']);
        $this->assertSame($cabinetA->getKey(), $patientA->cabinet_id);

        $this->actingAs($userB);
        $patientB = Patient::factory()->create(['first_name' => 'Bob']);
        $this->assertSame($cabinetB->getKey(), $patientB->cabinet_id);

        // User A must only see patient A.
        $this->actingAs($userA);
        $visible = Patient::query()->pluck('id')->all();
        $this->assertContains($patientA->id, $visible);
        $this->assertNotContains($patientB->id, $visible);

        // The escape hatch reveals every patient regardless of tenant.
        $this->assertSame(2, Patient::withoutCabinetScope()->count());
    }

    public function test_auto_fill_assigns_the_current_cabinet_on_create(): void
    {
        [$cabinet, $user] = $this->makeCabinetWithUser('owner@example.com');

        $this->actingAs($user);
        $patient = Patient::factory()->create();

        $this->assertSame($cabinet->getKey(), $patient->cabinet_id);
    }

    public function test_platform_admin_bypasses_the_scope_even_when_attached_to_a_cabinet(): void
    {
        [$cabinetA, $userA] = $this->makeCabinetWithUser('first@example.com');
        [$cabinetB, $userB] = $this->makeCabinetWithUser('second@example.com');

        $this->actingAs($userA);
        Patient::factory()->create(['first_name' => 'First']);
        $this->actingAs($userB);
        Patient::factory()->create(['first_name' => 'Second']);

        $platform = User::factory()->create([
            'cabinet_id' => $cabinetA->getKey(),
            'is_platform_admin' => true,
        ]);
        $this->actingAs($platform);

        $this->assertSame(2, Patient::query()->count());

        $unscoped = Patient::factory()->create(['first_name' => 'Platform']);
        $this->assertNull($unscoped->cabinet_id);
        $this->assertSame(3, Patient::query()->count());
        $this->assertNotSame($cabinetB->getKey(), $unscoped->cabinet_id);
    }

    public function test_an_explicit_foreign_cabinet_is_replaced_with_the_actors_cabinet(): void
    {
        [$cabinetA, $userA] = $this->makeCabinetWithUser('explicit-a@example.com');
        [$cabinetB] = $this->makeCabinetWithUser('explicit-b@example.com');

        $this->actingAs($userA);
        $patient = Patient::factory()->create([
            'cabinet_id' => $cabinetB->getKey(),
        ]);

        $this->assertSame($cabinetA->getKey(), $patient->cabinet_id);
        $this->assertTrue($patient->cabinet->is($cabinetA));
    }

    public function test_console_style_creation_can_still_assign_an_explicit_cabinet(): void
    {
        [$cabinet] = $this->makeCabinetWithUser('console-cabinet@example.com');
        auth()->logout();

        $patient = Patient::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
        ]);

        $this->assertSame($cabinet->getKey(), $patient->cabinet_id);
    }

    public function test_a_cabinet_member_cannot_move_a_record_to_another_cabinet(): void
    {
        [$cabinetA, $userA] = $this->makeCabinetWithUser('immutable-a@example.com');
        [$cabinetB] = $this->makeCabinetWithUser('immutable-b@example.com');

        $this->actingAs($userA);
        $patient = Patient::factory()->create();
        $patient->forceFill(['cabinet_id' => $cabinetB->getKey()])->save();

        $this->assertSame($cabinetA->getKey(), $patient->fresh()->cabinet_id);
    }

    public function test_a_tenant_cannot_save_a_foreign_record_loaded_through_an_escape_hatch(): void
    {
        [, $userA] = $this->makeCabinetWithUser('foreign-save-a@example.com');
        [, $userB] = $this->makeCabinetWithUser('foreign-save-b@example.com');

        $this->actingAs($userB);
        $foreignPatient = Patient::factory()->create(['first_name' => 'Original']);

        $this->actingAs($userA);
        $loaded = Patient::withoutCabinetScope()->findOrFail($foreignPatient->getKey());
        $loaded->first_name = 'Forbidden change';

        $this->expectException(AuthorizationException::class);
        $loaded->save();
    }

    public function test_resource_policies_reject_models_from_another_cabinet(): void
    {
        [, $userA] = $this->makeCabinetWithUser('policy-a@example.com');
        [, $userB] = $this->makeCabinetWithUser('policy-b@example.com');

        $this->actingAs($userB);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->getKey(),
            'created_by' => $userB->getKey(),
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->getKey(),
            'provider_id' => $userB->getKey(),
        ]);

        foreach ([$patient, $appointment, $encounter] as $resource) {
            $this->assertTrue(Gate::forUser($userB)->allows('view', $resource));
            $this->assertFalse(Gate::forUser($userA)->allows('view', $resource));
        }
    }
}
