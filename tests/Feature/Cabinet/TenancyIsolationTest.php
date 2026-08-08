<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_an_explicit_cabinet_is_preserved_and_the_relation_resolves(): void
    {
        [, $userA] = $this->makeCabinetWithUser('explicit-a@example.com');
        [$cabinetB] = $this->makeCabinetWithUser('explicit-b@example.com');

        $this->actingAs($userA);
        $patient = Patient::factory()->create([
            'cabinet_id' => $cabinetB->getKey(),
        ]);

        $this->assertSame($cabinetB->getKey(), $patient->cabinet_id);
        $this->assertTrue($patient->cabinet->is($cabinetB));
    }
}
