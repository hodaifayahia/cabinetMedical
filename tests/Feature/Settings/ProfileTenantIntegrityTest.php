<?php

namespace Tests\Feature\Settings;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_super_administrator_in_another_cabinet_does_not_bypass_the_last_admin_guard(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $lastAdministrator = $this->createSuperAdministrator($cabinetA);
        $this->createSuperAdministrator($cabinetB);

        $this->actingAs($lastAdministrator)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($lastAdministrator);
        $this->assertNotNull($lastAdministrator->fresh());
    }

    public function test_a_cabinet_super_administrator_can_delete_their_profile_when_a_peer_remains(): void
    {
        $cabinet = $this->createCabinet('Cabinet A');
        $administrator = $this->createSuperAdministrator($cabinet);
        $this->createSuperAdministrator($cabinet);

        $this->actingAs($administrator)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($administrator->fresh());
    }

    public function test_a_platform_super_administrator_retains_the_global_guard_behavior(): void
    {
        $platformAdministrator = User::factory()->create(['is_platform_admin' => true]);
        $platformAdministrator->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
        $this->createSuperAdministrator($this->createCabinet('Cabinet A'));

        $this->actingAs($platformAdministrator)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($platformAdministrator->fresh());
    }

    private function createCabinet(string $name): Cabinet
    {
        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function createSuperAdministrator(Cabinet $cabinet): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole(RoleName::SUPER_ADMINISTRATOR->value);

        return $user;
    }
}
