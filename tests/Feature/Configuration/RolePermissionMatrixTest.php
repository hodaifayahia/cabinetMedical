<?php

namespace Tests\Feature\Configuration;

use App\Enums\CabinetStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetRolePermissionSet;
use App\Models\CabinetSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cabinet = $this->createCabinet('Cabinet Permissions');
    }

    public function test_staff_manager_sees_permission_rows_role_columns_and_cabinet_users(): void
    {
        $administrator = $this->cabinetUser($this->cabinet, RoleName::ADMINISTRATOR);
        $this->cabinet->forceFill(['owner_user_id' => $administrator->getKey()])->save();

        $this->actingAs($administrator)
            ->get(route('app.configuration.roles-permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/RolesPermissions')
                ->has('permissionGroups', 12)
                ->where('permissionGroups.0.key', 'patients')
                ->where('permissionGroups.0.permissions.0.name', PermissionName::PATIENTS_VIEW->value)
                ->has('roles', count(RoleName::cases()))
                ->where('roles.0.name', RoleName::SUPER_ADMINISTRATOR->value)
                ->where('roles.0.locked', true)
                ->where('roles.0.permission_count', count(PermissionName::cases()))
                ->has('users', 1)
                ->where('users.0.id', $administrator->getKey())
                ->where('users.0.is_owner', true)
                ->has('assignableRoles', count(RoleName::cases()) - 1),
            );
    }

    public function test_a_doctor_can_open_and_save_the_matrix_without_staff_manage_permission(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);

        $this->assertFalse($doctor->hasPermissionTo(PermissionName::STAFF_MANAGE->value));

        $this->actingAs($doctor)
            ->get(route('app.configuration.roles-permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.can.manageRolePermissions', true));

        $payload = $this->matrixPayload();
        $this->setRolePermissions($payload, RoleName::RECEPTIONIST, [
            PermissionName::PATIENTS_VIEW->value,
        ]);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), [
                'roles' => $payload,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cabinet_role_permission_sets', [
            'cabinet_id' => $this->cabinet->getKey(),
            'role_name' => RoleName::RECEPTIONIST->value,
        ]);
    }

    public function test_grants_and_revocations_affect_only_users_with_that_role_in_the_current_cabinet(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $receptionist = $this->cabinetUser($this->cabinet, RoleName::RECEPTIONIST);
        $otherCabinet = $this->createCabinet('Autre cabinet');
        $otherReceptionist = $this->cabinetUser($otherCabinet, RoleName::RECEPTIONIST);
        $payload = $this->matrixPayload();
        $receptionistPermissions = $this->permissionsFor($payload, RoleName::RECEPTIONIST);
        $receptionistPermissions = array_values(array_diff($receptionistPermissions, [
            PermissionName::APPOINTMENTS_VIEW->value,
        ]));
        $receptionistPermissions[] = PermissionName::PAYMENTS_REFUND->value;
        $this->setRolePermissions($payload, RoleName::RECEPTIONIST, $receptionistPermissions);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), [
                'roles' => $payload,
            ])
            ->assertRedirect();

        $this->assertFalse($receptionist->fresh()->can(PermissionName::APPOINTMENTS_VIEW->value));
        $this->assertTrue($receptionist->fresh()->can(PermissionName::PAYMENTS_REFUND->value));

        $this->assertTrue($otherReceptionist->fresh()->can(PermissionName::APPOINTMENTS_VIEW->value));
        $this->assertFalse($otherReceptionist->fresh()->can(PermissionName::PAYMENTS_REFUND->value));

        $globalReceptionist = Role::findByName(RoleName::RECEPTIONIST->value, 'web');
        $this->assertTrue($globalReceptionist->hasPermissionTo(PermissionName::APPOINTMENTS_VIEW->value));
        $this->assertFalse($globalReceptionist->hasPermissionTo(PermissionName::PAYMENTS_REFUND->value));
    }

    public function test_an_empty_role_allow_list_is_an_explicit_revoke_all_override(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $cashier = $this->cabinetUser($this->cabinet, RoleName::CASHIER);
        $payload = $this->matrixPayload();
        $this->setRolePermissions($payload, RoleName::CASHIER, []);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), [
                'roles' => $payload,
            ])
            ->assertRedirect();

        $set = CabinetRolePermissionSet::withoutCabinetScope()
            ->where('cabinet_id', $this->cabinet->getKey())
            ->where('role_name', RoleName::CASHIER->value)
            ->sole();

        $this->assertSame([], $set->permissions);
        $this->assertFalse($cashier->fresh()->can(PermissionName::PAYMENTS_VIEW->value));
        $this->assertSame([], $cashier->fresh()->getAllPermissions()->all());
    }

    public function test_doctor_can_assign_a_canonical_non_privileged_role_to_a_same_cabinet_user(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $member = $this->cabinetUser($this->cabinet, RoleName::RECEPTIONIST);
        $payload = $this->matrixPayload();
        $this->setRolePermissions($payload, RoleName::CASHIER, [
            PermissionName::PATIENTS_VIEW->value,
        ]);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), [
                'roles' => $payload,
            ])
            ->assertRedirect();

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.users.role.update', $member), [
                'role' => RoleName::CASHIER->value,
            ])
            ->assertRedirect();

        $this->assertTrue($member->fresh()->hasRole(RoleName::CASHIER->value));
        $this->assertFalse($member->fresh()->hasRole(RoleName::RECEPTIONIST->value));
        $this->assertTrue($member->fresh()->can(PermissionName::PATIENTS_VIEW->value));
        $this->assertFalse($member->fresh()->can(PermissionName::PAYMENTS_VIEW->value));
    }

    public function test_doctor_cannot_assign_or_edit_the_super_administrator_role(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $member = $this->cabinetUser($this->cabinet, RoleName::RECEPTIONIST);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.users.role.update', $member), [
                'role' => RoleName::SUPER_ADMINISTRATOR->value,
            ])
            ->assertSessionHasErrors('role');

        $payload = $this->matrixPayload();
        $payload[] = [
            'name' => RoleName::SUPER_ADMINISTRATOR->value,
            'permissions' => [],
        ];

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), [
                'roles' => $payload,
            ])
            ->assertSessionHasErrors('roles');

        $this->assertFalse($member->fresh()->hasRole(RoleName::SUPER_ADMINISTRATOR->value));
        $this->assertDatabaseMissing('cabinet_role_permission_sets', [
            'cabinet_id' => $this->cabinet->getKey(),
            'role_name' => RoleName::SUPER_ADMINISTRATOR->value,
        ]);
    }

    public function test_role_assignment_is_same_cabinet_only_and_cannot_modify_another_owner(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $owner = $this->cabinetUser($this->cabinet, RoleName::ADMINISTRATOR);
        $this->cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();
        $otherCabinet = $this->createCabinet('Cabinet étranger');
        $otherMember = $this->cabinetUser($otherCabinet, RoleName::RECEPTIONIST);

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.users.role.update', $otherMember), [
                'role' => RoleName::CASHIER->value,
            ])
            ->assertNotFound();

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.users.role.update', $owner), [
                'role' => RoleName::CASHIER->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($otherMember->fresh()->hasRole(RoleName::RECEPTIONIST->value));
        $this->assertTrue($owner->fresh()->hasRole(RoleName::ADMINISTRATOR->value));
    }

    public function test_unprivileged_and_platform_users_cannot_access_or_mutate_a_cabinet_matrix(): void
    {
        $receptionist = $this->cabinetUser($this->cabinet, RoleName::RECEPTIONIST);
        $platformAdministrator = User::factory()->create([
            'is_platform_admin' => true,
            'approved_at' => now(),
        ]);
        $payload = $this->matrixPayload();

        $this->actingAs($receptionist)
            ->get(route('app.configuration.roles-permissions.index'))
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->put(route('app.configuration.roles-permissions.update'), ['roles' => $payload])
            ->assertForbidden();
        $this->actingAs($platformAdministrator)
            ->get(route('app.configuration.roles-permissions.index'))
            ->assertForbidden();
    }

    public function test_matrix_rejects_unknown_permissions_and_incomplete_role_sets(): void
    {
        $doctor = $this->cabinetUser($this->cabinet, RoleName::DOCTOR);
        $payload = $this->matrixPayload();
        $payload[0]['permissions'][] = 'platform.root';

        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), ['roles' => $payload])
            ->assertSessionHasErrors('roles.0.permissions.'.(count($payload[0]['permissions']) - 1));

        array_pop($payload);
        $this->actingAs($doctor)
            ->put(route('app.configuration.roles-permissions.update'), ['roles' => $payload])
            ->assertSessionHasErrors('roles');
    }

    public function test_configuration_index_still_routes_a_staff_only_manager_to_the_matrix(): void
    {
        $manager = $this->cabinetUser($this->cabinet);
        $manager->givePermissionTo(PermissionName::STAFF_MANAGE->value);

        $this->actingAs($manager)
            ->get(route('app.configuration.index'))
            ->assertRedirect(route('app.configuration.roles-permissions.index'));
    }

    private function createCabinet(string $name): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        CabinetSetting::current($cabinet);

        return $cabinet;
    }

    private function cabinetUser(Cabinet $cabinet, ?RoleName $role = null): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($cabinet)->getKey(),
            'approved_at' => now(),
        ]);

        if ($role !== null) {
            $user->assignRole($role->value);
        }

        return $user;
    }

    /** @return list<array{name: string, permissions: list<string>}> */
    private function matrixPayload(): array
    {
        return array_values(collect(RoleName::cases())
            ->reject(static fn (RoleName $role): bool => $role === RoleName::SUPER_ADMINISTRATOR)
            ->map(static function (RoleName $role): array {
                $storedRole = Role::findByName($role->value, 'web');

                return [
                    'name' => $role->value,
                    'permissions' => array_values($storedRole->permissions
                        ->pluck('name')
                        ->map(static fn (mixed $name): string => (string) $name)
                        ->values()
                        ->all()),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @param  list<array{name: string, permissions: list<string>}>  $payload
     * @return list<string>
     */
    private function permissionsFor(array $payload, RoleName $role): array
    {
        return collect($payload)->firstWhere('name', $role->value)['permissions'];
    }

    /**
     * @param  list<array{name: string, permissions: list<string>}>  $payload
     * @param  list<string>  $permissions
     */
    private function setRolePermissions(array &$payload, RoleName $role, array $permissions): void
    {
        foreach ($payload as &$rolePayload) {
            if ($rolePayload['name'] === $role->value) {
                $rolePayload['permissions'] = $permissions;

                return;
            }
        }
    }
}
