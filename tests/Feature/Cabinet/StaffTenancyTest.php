<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use App\Services\Cabinet\CabinetEntitlementService;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class StaffTenancyTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_cabinet_administrator_only_lists_staff_from_their_own_cabinet(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $administrator = $this->createUser(
            $cabinetA,
            RoleName::ADMINISTRATOR,
            ['name' => 'Alpha Administrator', 'email' => 'admin-a@example.test'],
        );
        $this->createUser(
            $cabinetA,
            RoleName::RECEPTIONIST,
            ['name' => 'Beta Receptionist', 'email' => 'staff-a@example.test'],
        );
        $this->createUser(
            $cabinetB,
            RoleName::ADMINISTRATOR,
            ['name' => 'Other Administrator', 'email' => 'admin-b@example.test'],
        );
        User::factory()->create([
            'name' => 'Platform Account',
            'email' => 'platform@example.test',
            'is_platform_admin' => true,
        ]);

        $this->actingAs($administrator)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Index')
                ->has('staff.data', 2)
                ->where('staff.total', 2)
                ->where('staff.data.0.email', 'admin-a@example.test')
                ->where('staff.data.1.email', 'staff-a@example.test'));
    }

    public function test_staff_creation_is_forced_into_the_actors_cabinet(): void
    {
        $this->activateSignedLicenseFeatures(['multi_user' => true]);
        $cabinet = $this->createCabinet('Cabinet A');
        $administrator = $this->createUser($cabinet, RoleName::ADMINISTRATOR);

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), $this->createPayload([
                'email' => 'detached@example.test',
                'assigned_to_cabinet' => false,
            ]))
            ->assertSessionHasErrors('assigned_to_cabinet');

        $this->assertDatabaseMissing('users', ['email' => 'detached@example.test']);

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), $this->createPayload())
            ->assertRedirect();

        $staff = User::query()->where('email', 'new-staff@example.test')->firstOrFail();

        $this->assertSame($cabinet->getKey(), $staff->cabinet_id);
        $this->assertSame(CabinetSetting::current($cabinet)->getKey(), $staff->cabinet_setting_id);
    }

    public function test_active_hosted_plan_allows_staff_without_a_machine_certificate(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Hosted Cabinet',
            'status' => CabinetStatus::PENDING,
        ]);
        CabinetSetting::current($cabinet)->update(['name' => $cabinet->name]);
        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::TRIAL);
        $administrator = $this->createUser($cabinet->fresh(), RoleName::ADMINISTRATOR);
        $entitlements = app(CabinetEntitlementService::class);

        $this->assertTrue($entitlements->featureEnabled($administrator, 'multi_user'));
        $this->assertFalse($entitlements->featureEnabled($administrator, 'remote_upload'));
        $this->assertFalse($entitlements->featureEnabled($administrator, 'google_drive_backup'));
        $this->assertFalse($entitlements->featureEnabled($administrator, 'automatic_updates'));

        $this->actingAs($administrator)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('multiUserCapability.available', true)
                ->where('multiUserCapability.reason', null));

        $this->post(route('app.staff.store'), $this->createPayload([
            'email' => 'hosted-staff@example.test',
        ]))->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'hosted-staff@example.test',
            'cabinet_id' => $cabinet->getKey(),
        ]);
    }

    public function test_unscoped_non_platform_administrator_cannot_create_staff(): void
    {
        $this->activateSignedLicenseFeatures(['multi_user' => true]);
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), $this->createPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new-staff@example.test']);
    }

    public function test_staff_creation_rejects_a_fourth_seat(): void
    {
        $this->activateSignedLicenseFeatures(['multi_user' => true]);
        $cabinet = $this->createCabinet('Full Cabinet');
        $administrator = $this->createUser($cabinet, RoleName::ADMINISTRATOR);
        $this->createUser($cabinet, RoleName::RECEPTIONIST);
        $this->createUser($cabinet, RoleName::CASHIER, ['approved_at' => null]);

        $this->assertSame(Cabinet::MAX_SEATS, $cabinet->seatsInUse());

        $this->actingAs($administrator)
            ->post(route('app.staff.store'), $this->createPayload())
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'new-staff@example.test']);
        $this->assertSame(Cabinet::MAX_SEATS, $cabinet->seatsInUse());
    }

    public function test_cabinet_administrator_cannot_update_or_delete_another_cabinets_user(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $administrator = $this->createUser($cabinetA, RoleName::ADMINISTRATOR);
        $otherStaff = $this->createUser(
            $cabinetB,
            RoleName::RECEPTIONIST,
            ['email' => 'other-staff@example.test'],
        );

        $this->actingAs($administrator)
            ->put(
                route('app.staff.update', $otherStaff),
                $this->updatePayload($otherStaff, ['role' => RoleName::CASHIER->value]),
            )
            ->assertForbidden();

        $this->actingAs($administrator)
            ->delete(route('app.staff.destroy', $otherStaff))
            ->assertForbidden();

        $this->assertTrue($otherStaff->fresh()->hasRole(RoleName::RECEPTIONIST->value));
    }

    public function test_legacy_assignment_fields_cannot_detach_or_transfer_staff(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $administrator = $this->createUser($cabinetA, RoleName::ADMINISTRATOR);
        $staff = $this->createUser($cabinetA, RoleName::RECEPTIONIST);

        $this->actingAs($administrator)
            ->put(
                route('app.staff.update', $staff),
                $this->updatePayload($staff, ['assigned_to_cabinet' => false]),
            )
            ->assertSessionHasErrors('assigned_to_cabinet');

        $this->actingAs($administrator)
            ->put(
                route('app.staff.update', $staff),
                $this->updatePayload($staff, [
                    'cabinet_id' => $cabinetB->getKey(),
                    'cabinet_setting_id' => CabinetSetting::current($cabinetB)->getKey(),
                ]),
            )
            ->assertRedirect();

        $staff->refresh();

        $this->assertSame($cabinetA->getKey(), $staff->cabinet_id);
        $this->assertSame(CabinetSetting::current($cabinetA)->getKey(), $staff->cabinet_setting_id);
    }

    public function test_last_super_administrator_guard_is_scoped_to_the_cabinet(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $superAdministrator = $this->createUser($cabinetA, RoleName::SUPER_ADMINISTRATOR);
        $this->createUser($cabinetB, RoleName::SUPER_ADMINISTRATOR);

        $this->actingAs($superAdministrator)
            ->put(
                route('app.staff.update', $superAdministrator),
                $this->updatePayload($superAdministrator, [
                    'role' => RoleName::ADMINISTRATOR->value,
                ]),
            )
            ->assertSessionHasErrors('role');

        $this->assertTrue(
            $superAdministrator->refresh()->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
        );
    }

    public function test_platform_administrator_retains_cross_cabinet_staff_access(): void
    {
        $cabinetA = $this->createCabinet('Cabinet A');
        $cabinetB = $this->createCabinet('Cabinet B');
        $staffA = $this->createUser($cabinetA, RoleName::RECEPTIONIST);
        $staffB = $this->createUser($cabinetB, RoleName::RECEPTIONIST);
        $platformAdministrator = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdministrator)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('staff.total', 3));

        $this->actingAs($platformAdministrator)
            ->put(
                route('app.staff.update', $staffA),
                $this->updatePayload($staffA, ['role' => RoleName::CASHIER->value]),
            )
            ->assertRedirect();

        $this->actingAs($platformAdministrator)
            ->delete(route('app.staff.destroy', $staffB))
            ->assertRedirect();

        $this->assertTrue($staffA->fresh()->hasRole(RoleName::CASHIER->value));
        $this->assertNull($staffB->fresh());
    }

    private function createCabinet(string $name): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        CabinetSetting::current($cabinet)->update(['name' => $name]);

        return $cabinet;
    }

    /** @param array<string, mixed> $attributes */
    private function createUser(Cabinet $cabinet, RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($cabinet)->getKey(),
            'approved_at' => now(),
            ...$attributes,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createPayload(array $overrides = []): array
    {
        return [
            'name' => 'New Staff',
            'email' => 'new-staff@example.test',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
            'role' => RoleName::RECEPTIONIST->value,
            'assigned_to_cabinet' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => $user->getRoleNames()->first() ?? RoleName::RECEPTIONIST->value,
            'assigned_to_cabinet' => true,
            ...$overrides,
        ];
    }
}
