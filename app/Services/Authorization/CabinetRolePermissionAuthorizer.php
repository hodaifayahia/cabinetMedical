<?php

namespace App\Services\Authorization;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;

class CabinetRolePermissionAuthorizer
{
    /**
     * Cabinet owners and doctors have an explicit management path that cannot
     * accidentally disappear while editing their own role profile. Existing
     * staff managers retain access. Platform accounts stay in the back office.
     */
    public function canManage(User $user): bool
    {
        if ($user->is_platform_admin || $user->cabinet_id === null || ! $user->isApproved()) {
            return false;
        }

        $isOwner = Cabinet::query()
            ->whereKey($user->cabinet_id)
            ->where('owner_user_id', $user->getKey())
            ->exists();

        return $isOwner
            || $user->hasRole(RoleName::DOCTOR->value)
            || $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
            || $user->checkPermissionTo(PermissionName::STAFF_MANAGE->value);
    }

    /** @return list<RoleName> */
    public function assignableRoles(User $user): array
    {
        return [RoleName::ASSISTANT];
    }
}
