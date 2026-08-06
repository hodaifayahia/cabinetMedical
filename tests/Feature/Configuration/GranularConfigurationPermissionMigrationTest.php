<?php

namespace Tests\Feature\Configuration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GranularConfigurationPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_backfills_legacy_roles_without_broadening_settings_only_roles(): void
    {
        $migration = require database_path(
            'migrations/2026_08_05_020000_add_granular_configuration_permissions.php',
        );
        assert($migration instanceof Migration);
        (new ReflectionMethod($migration, 'down'))->invoke($migration);

        Permission::findOrCreate('configuration.manage', 'web');
        Permission::findOrCreate('settings.manage', 'web');
        $brandingRole = Role::findOrCreate('Legacy branding role', 'web');
        $brandingRole->givePermissionTo('configuration.manage');
        $sensitiveRole = Role::findOrCreate('Legacy sensitive role', 'web');
        $sensitiveRole->givePermissionTo([
            'configuration.manage',
            'settings.manage',
        ]);
        $settingsOnlyRole = Role::findOrCreate('Legacy settings-only role', 'web');
        $settingsOnlyRole->givePermissionTo('settings.manage');

        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $brandingRole->refresh();
        $sensitiveRole->refresh();
        $settingsOnlyRole->refresh();

        $this->assertTrue($brandingRole->hasPermissionTo('configuration.branding.manage'));
        $this->assertFalse($brandingRole->hasPermissionTo('configuration.backups.manage'));

        foreach ([
            'configuration.branding.manage',
            'configuration.connectivity.manage',
            'configuration.backups.manage',
            'configuration.restore.manage',
            'configuration.drive.manage',
            'configuration.licensing.manage',
            'configuration.diagnostics.view',
        ] as $permission) {
            $this->assertTrue(
                $sensitiveRole->hasPermissionTo($permission),
                "Expected legacy sensitive role to receive {$permission}.",
            );
        }

        foreach ([
            'configuration.branding.manage',
            'configuration.connectivity.manage',
            'configuration.backups.manage',
            'configuration.restore.manage',
            'configuration.drive.manage',
            'configuration.licensing.manage',
            'configuration.diagnostics.view',
        ] as $permission) {
            $this->assertFalse(
                $settingsOnlyRole->hasPermissionTo($permission),
                "Settings-only role must not receive {$permission}.",
            );
        }
    }
}
