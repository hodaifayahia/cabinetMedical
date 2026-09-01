<?php

namespace Tests\Feature\Configuration;

use App\Enums\CabinetStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reproduces the production 500 on Roles & permissions.
 *
 * `PermissionName` is the code's view of the world; the `permissions` table is
 * the database's. They diverge whenever a deployment adds an enum case and the
 * seeder is not re-run — which is the normal state of a server that has had
 * `php artisan migrate` but not `db:seed`.
 *
 * Spatie resolves a permission NAME through Permission::findByName(), which
 * throws PermissionDoesNotExist rather than returning false. So a single
 * missing row turns an authorization question into a 500.
 */
class MissingPermissionRowTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Probe',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        CabinetSetting::current($this->cabinet);

        $this->doctor = User::factory()->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $this->doctor->assignRole(RoleName::DOCTOR->value);

        $this->cabinet->forceFill(['owner_user_id' => $this->doctor->getKey()])->save();
    }

    /**
     * Drop a permission row the way a un-reseeded deployment would: the enum
     * still lists it, the table no longer has it.
     */
    private function dropPermissionRow(string $name): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', $name)->pluck('id'))
            ->delete();

        DB::table('permissions')->where('name', $name)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_matrix_survives_a_permission_the_database_does_not_have(): void
    {
        $this->dropPermissionRow(PermissionName::STAFF_MANAGE->value);

        $this->actingAs($this->doctor)
            ->get('/app/configuration/roles-permissions')
            ->assertSuccessful();
    }

    public function test_every_enum_permission_can_be_asked_about_without_throwing(): void
    {
        // The whole enum, one row at a time: any single missing name must
        // degrade to "not granted", never to an exception.
        foreach (PermissionName::cases() as $case) {
            $this->dropPermissionRow($case->value);
        }

        foreach (PermissionName::cases() as $case) {
            $this->assertFalse(
                $this->doctor->fresh()->can($case->value),
                "asking about the missing permission {$case->value} must return false",
            );
        }
    }

    public function test_shared_inertia_props_survive_a_missing_permission(): void
    {
        // HandleInertiaRequests builds `auth.user.can` on EVERY page, so if this
        // throws the whole application is down, not just one screen.
        $this->dropPermissionRow(PermissionName::STAFF_MANAGE->value);

        $this->actingAs($this->doctor)
            ->get('/dashboard')
            ->assertSuccessful();
    }
}
