<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()
            ->where('name', RoleName::DOCTOR->value)
            ->where('guard_name', 'web')
            ->first();

        // Roles are normally seeded after migrations on a fresh install.
        if ($role === null) {
            return;
        }

        $permission = Permission::findOrCreate(PermissionName::APPOINTMENTS_CREATE->value, 'web');
        $role->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()
            ->where('name', RoleName::DOCTOR->value)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            return;
        }

        $role->revokePermissionTo(PermissionName::APPOINTMENTS_CREATE->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
