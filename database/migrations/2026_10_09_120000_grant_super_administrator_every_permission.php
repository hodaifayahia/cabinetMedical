<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The super administrator (Doctor) role holds every permission, but only the
 * seeder granted them. A desktop database seeded by an older release, or one
 * whose role was created on demand at cabinet creation, left the cabinet
 * owner with a dashboard and nothing else. Create any permission that is
 * missing and give the role all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = array_map(
            static fn (string $name) => Permission::findOrCreate($name, 'web'),
            PermissionName::values(),
        );

        Role::findOrCreate(RoleName::SUPER_ADMINISTRATOR->value, 'web')
            ->givePermissionTo($permissions);

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Granting is not undone: removing permissions from the super
        // administrator would lock cabinet owners out again.
    }
};
