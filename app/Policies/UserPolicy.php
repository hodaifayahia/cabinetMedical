<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::STAFF_MANAGE->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model)
            || $user->can(PermissionName::STAFF_MANAGE->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::STAFF_MANAGE->value);
    }

    public function update(User $user, User $model): bool
    {
        if ($model->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
            && ! $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value)) {
            return false;
        }

        return $user->is($model)
            || $user->can(PermissionName::STAFF_MANAGE->value);
    }

    public function delete(User $user, User $model): bool
    {
        return ! $user->is($model)
            && (! $model->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                || $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value))
            && $user->can(PermissionName::STAFF_MANAGE->value);
    }
}
