<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = [];
        foreach (PermissionName::values() as $permission) {
            $permissions[$permission] = Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(RoleName::DOCTOR->value, 'web')
            ->syncPermissions(array_values($permissions));

        Role::findOrCreate(RoleName::ASSISTANT->value, 'web')
            ->syncPermissions(array_map(
                static fn (string $permission): Permission => $permissions[$permission],
                $this->assistantPermissions(),
            ));

        Role::query()->whereNotIn('name', RoleName::values())->get()->each->delete();
        $registrar->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function assistantPermissions(): array
    {
        return [
            PermissionName::PATIENTS_VIEW->value,
            PermissionName::PATIENTS_CREATE->value,
            PermissionName::PATIENTS_UPDATE->value,
            PermissionName::APPOINTMENTS_VIEW->value,
            PermissionName::APPOINTMENTS_CREATE->value,
            PermissionName::APPOINTMENTS_UPDATE->value,
            PermissionName::APPOINTMENTS_CANCEL->value,
            PermissionName::APPOINTMENTS_CHECK_IN->value,
            PermissionName::PAYMENTS_VIEW->value,
            PermissionName::PAYMENTS_CREATE->value,
        ];
    }
}
