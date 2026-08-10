<?php

namespace App\Services\Authorization;

use App\Models\CabinetRolePermissionSet;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CabinetRolePermissionService
{
    public function allows(User $user, PermissionContract $permission): bool
    {
        if ($user->hasDirectPermission($permission)) {
            return true;
        }

        /** @var Collection<int, Role> $roles */
        $roles = $user->loadMissing('roles', 'roles.permissions')->roles;

        if ($user->cabinet_id === null || $roles->isEmpty()) {
            return $roles->contains(
                static fn ($role): bool => $role->hasPermissionTo($permission),
            );
        }

        $sets = $this->setsFor($user, $this->roleNames($roles));

        return $roles->contains(function (Role $role) use ($permission, $sets): bool {
            /** @var CabinetRolePermissionSet|null $set */
            $set = $sets->get((string) $role->name);

            if ($set === null) {
                return $role->hasPermissionTo($permission);
            }

            return in_array((string) $permission->name, $set->permissionNames(), true);
        });
    }

    /**
     * Resolve the permissions exposed to middleware, policies, Inertia and API
     * resources. Direct user grants remain additive; cabinet role sets replace
     * only the matching global role's defaults.
     *
     * @return Collection<int, PermissionContract>
     */
    public function effectivePermissions(User $user): Collection
    {
        $user->loadMissing('permissions', 'roles', 'roles.permissions');
        /** @var Collection<int, Role> $roles */
        $roles = $user->roles;
        /** @var Collection<int, PermissionContract> $directPermissions */
        $directPermissions = $user->permissions;

        if ($user->cabinet_id === null || $roles->isEmpty()) {
            $permissions = $directPermissions;
            foreach ($roles as $role) {
                /** @var Collection<int, PermissionContract> $rolePermissions */
                $rolePermissions = $role->permissions;
                $permissions = $permissions->merge($rolePermissions);
            }

            return $permissions
                ->unique('name')
                ->sortBy('name')
                ->values();
        }

        $sets = $this->setsFor($user, $this->roleNames($roles));
        $names = $directPermissions->pluck('name');

        foreach ($roles as $role) {
            /** @var CabinetRolePermissionSet|null $set */
            $set = $sets->get((string) $role->name);
            $names = $names->merge(
                $set === null
                    ? $role->permissions->pluck('name')
                    : $set->permissionNames(),
            );
        }

        $names = $names
            ->map(static fn (mixed $name): string => (string) $name)
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, PermissionContract> $permissions */
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names->all())
            ->get()
            ->sortBy('name')
            ->values();

        return $permissions;
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<string, CabinetRolePermissionSet>
     */
    private function setsFor(User $user, array $roleNames): Collection
    {
        return CabinetRolePermissionSet::withoutCabinetScope()
            ->where('cabinet_id', $user->cabinet_id)
            ->whereIn('role_name', $roleNames)
            ->get()
            ->keyBy('role_name');
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<string>
     */
    private function roleNames(Collection $roles): array
    {
        return array_values($roles
            ->map(static fn (Role $role): string => (string) $role->name)
            ->values()
            ->all());
    }
}
