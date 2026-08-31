<?php

namespace Tests\Feature\Configuration;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The 7-role -> 2-role consolidation is a data migration that runs against
 * live clinic databases. It must not cost anybody their access.
 *
 * It previously created 'Assistant' with no permissions at all and left each
 * cabinet's permission overrides keyed by the deleted role names, which
 * CabinetRolePermissionService then could not find - so every non-doctor user
 * in every cabinet silently ended up able to do nothing.
 */
class RoleConsolidationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase runs migrations but not seeders, so the permissions
        // table would otherwise be empty and every grant below a no-op. A real
        // installation always has them.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function createCabinet(): Cabinet
    {
        return Cabinet::query()->create([
            'name' => 'Cabinet '.fake()->unique()->word(),
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function runConsolidation(): void
    {
        $migration = require database_path(
            'migrations/2026_08_10_000000_consolidate_roles_to_doctor_and_assistant.php',
        );

        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedLegacyRole(string $name, array $permissionNames): int
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['name' => $name, 'guard_name' => 'web'],
            ['created_at' => $now, 'updated_at' => $now],
        );

        $roleId = (int) DB::table('roles')
            ->where(['name' => $name, 'guard_name' => 'web'])
            ->value('id');

        $rows = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->map(static fn (int $id): array => ['role_id' => $roleId, 'permission_id' => $id])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        return $roleId;
    }

    public function test_a_legacy_assistant_role_keeps_working_permissions(): void
    {
        $cabinet = $this->createCabinet();
        $user = User::factory()->create(['cabinet_id' => $cabinet->getKey()]);

        $receptionistId = $this->seedLegacyRole('Receptionist', [
            'patients.view',
            'appointments.view',
        ]);

        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $receptionistId,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
        ]);

        $this->runConsolidation();

        $fresh = User::query()->findOrFail($user->getKey());

        $this->assertTrue(
            $fresh->hasRole('Assistant'),
            'the legacy Receptionist should now hold the Assistant role',
        );

        $this->assertTrue(
            $fresh->can('patients.view'),
            'consolidating roles must not strip a receptionist of patient access',
        );
    }

    public function test_cabinet_permission_sets_are_rekeyed_onto_the_surviving_role(): void
    {
        $cabinet = $this->createCabinet();

        $this->seedLegacyRole('Receptionist', ['patients.view']);
        $this->seedLegacyRole('Cashier', ['payments.view']);

        $now = now();
        DB::table('cabinet_role_permission_sets')->insert([
            [
                'cabinet_id' => $cabinet->getKey(),
                'role_name' => 'Receptionist',
                'permissions' => json_encode(['patients.view', 'appointments.view']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'cabinet_id' => $cabinet->getKey(),
                'role_name' => 'Cashier',
                'permissions' => json_encode(['payments.view']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->runConsolidation();

        $this->assertSame(
            0,
            DB::table('cabinet_role_permission_sets')
                ->whereIn('role_name', ['Receptionist', 'Cashier'])
                ->count(),
            'sets keyed by a deleted role name are unreachable and must not survive',
        );

        $set = DB::table('cabinet_role_permission_sets')
            ->where('cabinet_id', $cabinet->getKey())
            ->where('role_name', 'Assistant')
            ->first();

        $this->assertNotNull($set, 'the merged Assistant set must exist');

        $permissions = json_decode((string) $set->permissions, true);

        // Union: nobody who could do something yesterday loses it today.
        $this->assertEqualsCanonicalizing(
            ['appointments.view', 'patients.view', 'payments.view'],
            $permissions,
        );
    }

    public function test_the_assistant_role_is_not_created_empty(): void
    {
        $this->seedLegacyRole('Receptionist', ['patients.view']);

        $this->runConsolidation();

        $assistantId = DB::table('roles')
            ->where(['name' => 'Assistant', 'guard_name' => 'web'])
            ->value('id');

        $this->assertNotNull($assistantId);

        $count = DB::table('role_has_permissions')->where('role_id', $assistantId)->count();

        $this->assertGreaterThan(
            0,
            $count,
            'an Assistant role with no permissions leaves every non-doctor user unable to act',
        );
    }

    public function test_running_it_twice_is_safe(): void
    {
        $this->seedLegacyRole('Receptionist', ['patients.view']);

        $this->runConsolidation();
        $this->runConsolidation();

        $this->assertSame(
            1,
            DB::table('roles')->where(['name' => 'Assistant', 'guard_name' => 'web'])->count(),
        );
    }
}
