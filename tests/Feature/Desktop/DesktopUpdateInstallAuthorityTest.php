<?php

namespace Tests\Feature\Desktop;

use App\Enums\CabinetStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\DesktopUpdateInstallAuthority;
use App\Services\InstallationMaintenanceAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DesktopUpdateInstallAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['medismart.runtime.desktop_supervised' => true]);
    }

    public function test_installation_maintenance_is_limited_to_platform_and_unscoped_accounts(): void
    {
        $service = app(InstallationMaintenanceAccessService::class);
        $cabinet = $this->cabinet();

        $this->assertFalse($service->allows(null));
        $this->assertTrue($service->allows(User::factory()->create(['is_platform_admin' => true])));
        $this->assertTrue($service->allows(User::factory()->create(['cabinet_id' => null])));
        $this->assertFalse($service->allows($this->member($cabinet, RoleName::DOCTOR)));
        $this->assertTrue($service->allows(User::factory()->create([
            'is_platform_admin' => true,
            'cabinet_id' => $cabinet->getKey(),
        ])));
    }

    public function test_installation_maintenance_denial_is_a_403_with_the_documented_message(): void
    {
        $member = $this->member($this->cabinet(), RoleName::DOCTOR);

        try {
            app(InstallationMaintenanceAccessService::class)->authorize($member);
            $this->fail('A tenant user reached installation maintenance.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(InstallationMaintenanceAccessService::DENIAL_MESSAGE, $exception->getMessage());
        }

        app(InstallationMaintenanceAccessService::class)->authorize(User::factory()->create());
        $this->addToAssertionCount(1);
    }

    public function test_nobody_signed_in_cannot_install_an_update(): void
    {
        $this->assertFalse($this->authority()->allows(null));
    }

    public function test_a_mobile_patient_can_never_install_an_update(): void
    {
        $patient = User::factory()->create(['approved_at' => now()]);
        $patient->assignRole(RoleName::PATIENT->value);

        $this->assertFalse($this->authority()->allows($patient));
    }

    public function test_the_patient_check_wins_over_platform_administration(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $admin->assignRole(RoleName::PATIENT->value);

        $this->assertFalse($this->authority()->allows($admin));
    }

    public function test_a_platform_administrator_may_install_even_off_the_supervised_desktop(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->assertTrue($this->authority()->allows(User::factory()->create(['is_platform_admin' => true])));
    }

    public function test_an_unscoped_account_needs_the_connectivity_permission(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($this->authority()->allows($user));

        $user->givePermissionTo(PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value);

        $this->assertTrue($this->authority()->allows($user->fresh()));
    }

    public function test_any_approved_member_of_the_single_cabinet_may_install_on_a_supervised_desktop(): void
    {
        $cabinet = $this->cabinet();

        $this->assertTrue($this->authority()->allows($this->member($cabinet, RoleName::DOCTOR)));
        $this->assertTrue($this->authority()->allows($this->member($cabinet, RoleName::ASSISTANT)));
    }

    public function test_an_unapproved_member_cannot_install(): void
    {
        $member = $this->member($this->cabinet(), RoleName::DOCTOR, approved: false);

        $this->assertFalse($this->authority()->allows($member));
    }

    public function test_a_member_cannot_install_outside_a_supervised_desktop(): void
    {
        $member = $this->member($this->cabinet(), RoleName::DOCTOR);
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->assertFalse($this->authority()->allows($member));
    }

    public function test_members_cannot_install_once_the_desktop_holds_a_second_cabinet(): void
    {
        $first = $this->cabinet();
        $second = $this->cabinet();

        $this->assertFalse($this->authority()->allows($this->member($first, RoleName::DOCTOR)));
        $this->assertFalse($this->authority()->allows($this->member($second, RoleName::DOCTOR)));
    }

    public function test_authorize_aborts_with_a_403_for_a_refused_account(): void
    {
        try {
            $this->authority()->authorize(null);
            $this->fail('An anonymous update installation was authorized.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertStringContainsString('mettre à jour', $exception->getMessage());
        }
    }

    public function test_authorize_passes_silently_for_an_allowed_account(): void
    {
        $this->authority()->authorize($this->member($this->cabinet(), RoleName::ASSISTANT));

        $this->addToAssertionCount(1);
    }

    private function authority(): DesktopUpdateInstallAuthority
    {
        return app(DesktopUpdateInstallAuthority::class);
    }

    private function cabinet(): Cabinet
    {
        return Cabinet::query()->create([
            'name' => 'Cabinet '.fake()->unique()->lastName(),
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function member(Cabinet $cabinet, RoleName $role, bool $approved = true): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => $approved ? now() : null,
        ]);
        $user->assignRole($role->value);

        return $user;
    }
}
