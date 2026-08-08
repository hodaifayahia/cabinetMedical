<?php

namespace Tests\Feature\Authorization;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class RolePermissionAccessTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cabinet = Cabinet::query()->create([
            'name' => 'Authorization Test Cabinet',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        CabinetSetting::current($this->cabinet);
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_super_administrator_can_access_the_filament_admin_panel()
    {
        // Under multi-cabinet tenancy the Filament back office is reserved for
        // platform staff; cabinet members (even super administrators) work in
        // the Inertia application instead. See User::canAccessPanel().
        $user = User::factory()->create(['is_platform_admin' => true]);
        $user->assignRole(RoleName::SUPER_ADMINISTRATOR->value);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }

    public function test_receptionist_cannot_access_the_filament_admin_panel()
    {
        $user = $this->cabinetUser();
        $user->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_administrator_can_view_the_staff_index()
    {
        $user = $this->cabinetUser();
        $user->assignRole(RoleName::ADMINISTRATOR->value);

        User::factory()->count(2)->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($this->cabinet)->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Index')
                ->has('staff.data', 3)
                ->where('staff.total', 3)
                ->where('multiUserCapability.available', false),
            );
    }

    public function test_receptionist_cannot_view_the_staff_index()
    {
        $user = $this->cabinetUser();
        $user->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($user)
            ->get(route('app.staff.index'))
            ->assertForbidden();
    }

    public function test_unknown_staff_role_filter_is_rejected_without_querying_the_role_store(): void
    {
        $administrator = $this->cabinetUser();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->from(route('app.staff.index'))
            ->get(route('app.staff.index', ['role' => 'Unknown role']))
            ->assertSessionHasErrors('role')
            ->assertRedirect(route('app.staff.index'));
    }

    public function test_administrator_can_add_and_assign_a_user_to_the_cabinet(): void
    {
        $this->activateSignedLicenseFeatures(['multi_user' => true]);
        $administrator = $this->cabinetUser();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet = CabinetSetting::current();

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), [
                'name' => 'Medical Assistant',
                'email' => 'assistant@example.test',
                'password' => 'secure-password',
                'password_confirmation' => 'secure-password',
                'role' => RoleName::RECEPTIONIST->value,
                'assigned_to_cabinet' => true,
            ])
            ->assertRedirect();

        $staff = User::query()->where('email', 'assistant@example.test')->firstOrFail();

        $this->assertTrue($staff->hasRole(RoleName::RECEPTIONIST->value));
        $this->assertSame($cabinet->id, $staff->cabinet_setting_id);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.user_created',
            'subject_id' => (string) $staff->getKey(),
            'user_id' => $administrator->getKey(),
        ]);
    }

    public function test_unlicensed_staff_creation_fails_closed_without_hiding_existing_accounts(): void
    {
        $administrator = $this->cabinetUser();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), [
                'name' => 'Unlicensed account',
                'email' => 'unlicensed@example.test',
                'password' => 'secure-password',
                'password_confirmation' => 'secure-password',
                'role' => RoleName::RECEPTIONIST->value,
                'assigned_to_cabinet' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unlicensed@example.test']);

        $this->actingAs($administrator)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff.total', 1)
                ->where('multiUserCapability.available', false)
                ->where('multiUserCapability.reason', fn (mixed $reason): bool => is_string($reason) && $reason !== ''));
    }

    public function test_administrator_can_change_a_users_function_and_cabinet_assignment(): void
    {
        $administrator = $this->cabinetUser();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $staff = $this->cabinetUser();
        $staff->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($administrator)
            ->put(route('app.staff.update', $staff), [
                'name' => $staff->name,
                'email' => $staff->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => RoleName::CASHIER->value,
                'assigned_to_cabinet' => true,
            ])
            ->assertRedirect();

        $staff->refresh();

        $this->assertTrue($staff->hasRole(RoleName::CASHIER->value));
        $this->assertSame(CabinetSetting::current()->id, $staff->cabinet_setting_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.user_updated',
            'subject_id' => (string) $staff->getKey(),
            'user_id' => $administrator->getKey(),
        ]);
        $this->assertSame(
            false,
            AuditLog::query()
                ->where('action', 'staff.user_updated')
                ->firstOrFail()
                ->metadata['credentials_changed'],
        );
    }

    public function test_an_administrator_cannot_modify_or_delete_a_super_administrator(): void
    {
        $administrator = $this->cabinetUser();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $superAdministrator = $this->cabinetUser();
        $superAdministrator->assignRole(RoleName::SUPER_ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->put(route('app.staff.update', $superAdministrator), [
                'name' => $superAdministrator->name,
                'email' => $superAdministrator->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => RoleName::ADMINISTRATOR->value,
                'assigned_to_cabinet' => true,
            ])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->delete(route('app.staff.destroy', $superAdministrator))
            ->assertForbidden();

        $this->assertTrue(
            $superAdministrator->refresh()->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
        );
    }

    public function test_the_last_super_administrator_cannot_demote_their_own_account(): void
    {
        $superAdministrator = $this->cabinetUser();
        $superAdministrator->assignRole(RoleName::SUPER_ADMINISTRATOR->value);

        $this->actingAs($superAdministrator)
            ->put(route('app.staff.update', $superAdministrator), [
                'name' => $superAdministrator->name,
                'email' => $superAdministrator->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => RoleName::ADMINISTRATOR->value,
                'assigned_to_cabinet' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue(
            $superAdministrator->refresh()->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
        );
    }

    public function test_a_super_administrator_can_remove_staff_and_the_action_is_audited(): void
    {
        $superAdministrator = $this->cabinetUser();
        $superAdministrator->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
        $staff = $this->cabinetUser();
        $staff->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($superAdministrator)
            ->delete(route('app.staff.destroy', $staff))
            ->assertRedirect();

        $this->assertNull($staff->fresh());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.user_deleted',
            'subject_id' => (string) $staff->getKey(),
            'user_id' => $superAdministrator->getKey(),
        ]);
    }

    public function test_dashboard_shares_roles_permissions_and_capabilities_for_authenticated_users()
    {
        $user = $this->cabinetUser();
        $user->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.roles', [RoleName::ADMINISTRATOR->value])
                ->where('auth.user.can.manageStaff', true)
                ->where('auth.user.can.accessAdminPanel', false),
            );
    }

    public function test_only_platform_staff_are_advertised_as_able_to_access_filament(): void
    {
        $platformAdministrator = User::factory()->create([
            'cabinet_id' => null,
            'cabinet_setting_id' => null,
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdministrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.can.accessAdminPanel', true),
            );
    }

    /** @param array<string, mixed> $attributes */
    private function cabinetUser(array $attributes = []): User
    {
        return User::factory()->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($this->cabinet)->getKey(),
            'approved_at' => now(),
            ...$attributes,
        ]);
    }
}
