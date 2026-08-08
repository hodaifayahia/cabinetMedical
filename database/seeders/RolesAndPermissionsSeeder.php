<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);

        $permissionRegistrar->forgetCachedPermissions();

        $permissionsByName = [];

        foreach (PermissionName::values() as $permission) {
            $permissionsByName[$permission] = Permission::findOrCreate($permission, 'web');
        }

        $permissionRegistrar->forgetCachedPermissions();

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(array_map(
                static fn (string $permission): PermissionContract => $permissionsByName[$permission],
                $permissions,
            ));
        }

        $permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolePermissions(): array
    {
        $allPermissions = PermissionName::values();

        return [
            RoleName::SUPER_ADMINISTRATOR->value => $allPermissions,
            RoleName::ADMINISTRATOR->value => $allPermissions,
            RoleName::DOCTOR->value => [
                PermissionName::PATIENTS_VIEW->value,
                PermissionName::PATIENTS_UPDATE->value,
                PermissionName::PATIENTS_VIEW_MEDICAL_RECORD->value,
                PermissionName::APPOINTMENTS_VIEW->value,
                PermissionName::APPOINTMENTS_CREATE->value,
                PermissionName::APPOINTMENTS_UPDATE->value,
                PermissionName::APPOINTMENTS_CONFIGURE->value,
                PermissionName::CONFIGURATION_MANAGE->value,
                PermissionName::CONFIGURATION_BRANDING_MANAGE->value,
                PermissionName::CONSULTATIONS_VIEW->value,
                PermissionName::CONSULTATIONS_CREATE->value,
                PermissionName::CONSULTATIONS_UPDATE->value,
                PermissionName::CONSULTATIONS_COMPLETE->value,
                PermissionName::ENCOUNTERS_VIEW->value,
                PermissionName::ENCOUNTERS_CREATE->value,
                PermissionName::ENCOUNTERS_UPDATE->value,
                PermissionName::ENCOUNTERS_SIGN->value,
                PermissionName::ENCOUNTERS_AMEND->value,
                PermissionName::PRESCRIPTIONS_VIEW->value,
                PermissionName::PRESCRIPTIONS_CREATE->value,
                PermissionName::PRESCRIPTIONS_PRINT->value,
                PermissionName::PAYMENTS_VIEW->value,
                PermissionName::PAYMENTS_CREATE->value,
                PermissionName::REPORTS_VIEW->value,
            ],
            RoleName::RECEPTIONIST->value => [
                PermissionName::PATIENTS_VIEW->value,
                PermissionName::PATIENTS_CREATE->value,
                PermissionName::PATIENTS_UPDATE->value,
                PermissionName::APPOINTMENTS_VIEW->value,
                PermissionName::APPOINTMENTS_CREATE->value,
                PermissionName::APPOINTMENTS_UPDATE->value,
                PermissionName::APPOINTMENTS_CANCEL->value,
                PermissionName::APPOINTMENTS_CHECK_IN->value,
                PermissionName::APPOINTMENTS_CONFIGURE->value,
                PermissionName::CONFIGURATION_MANAGE->value,
                PermissionName::CONFIGURATION_BRANDING_MANAGE->value,
                PermissionName::PAYMENTS_VIEW->value,
                PermissionName::PAYMENTS_CREATE->value,
            ],
            RoleName::CASHIER->value => [
                PermissionName::PATIENTS_VIEW->value,
                PermissionName::SALES_VIEW->value,
                PermissionName::SALES_CREATE->value,
                PermissionName::SALES_REFUND->value,
                PermissionName::SALES_CANCEL->value,
                PermissionName::PAYMENTS_VIEW->value,
                PermissionName::PAYMENTS_CREATE->value,
                PermissionName::PAYMENTS_REFUND->value,
            ],
            RoleName::STOCK_MANAGER->value => [
                PermissionName::PRODUCTS_VIEW->value,
                PermissionName::PRODUCTS_CREATE->value,
                PermissionName::PRODUCTS_UPDATE->value,
                PermissionName::PRODUCTS_DELETE->value,
                PermissionName::STOCK_VIEW->value,
                PermissionName::STOCK_PURCHASE->value,
                PermissionName::STOCK_ADJUST->value,
                PermissionName::STOCK_VIEW_COST->value,
                PermissionName::REPORTS_VIEW->value,
            ],
            RoleName::PHARMACIST->value => [
                PermissionName::PATIENTS_VIEW->value,
                PermissionName::PATIENTS_VIEW_MEDICAL_RECORD->value,
                PermissionName::PRESCRIPTIONS_VIEW->value,
                PermissionName::PRESCRIPTIONS_PRINT->value,
                PermissionName::PRODUCTS_VIEW->value,
                PermissionName::STOCK_VIEW->value,
            ],
        ];
    }
}
