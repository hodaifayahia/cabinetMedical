<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private const GRANULAR_PERMISSIONS = [
        'configuration.branding.manage',
        'configuration.connectivity.manage',
        'configuration.backups.manage',
        'configuration.restore.manage',
        'configuration.drive.manage',
        'configuration.licensing.manage',
        'configuration.diagnostics.view',
    ];

    /** @var list<string> */
    private const SENSITIVE_PERMISSIONS = [
        'configuration.connectivity.manage',
        'configuration.backups.manage',
        'configuration.restore.manage',
        'configuration.drive.manage',
        'configuration.licensing.manage',
        'configuration.diagnostics.view',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::GRANULAR_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $brandingRoles = Role::query()
            ->whereHas('permissions', static fn ($query) => $query
                ->where('name', 'configuration.manage')
                ->where('guard_name', 'web'))
            ->get();

        foreach ($brandingRoles as $role) {
            $role->givePermissionTo('configuration.branding.manage');
        }

        $sensitiveRoles = Role::query()
            ->whereHas('permissions', static fn ($query) => $query
                ->where('name', 'configuration.manage')
                ->where('guard_name', 'web'))
            ->whereHas('permissions', static fn ($query) => $query
                ->where('name', 'settings.manage')
                ->where('guard_name', 'web'))
            ->get();

        foreach ($sensitiveRoles as $role) {
            $role->givePermissionTo(self::SENSITIVE_PERMISSIONS);
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::GRANULAR_PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
