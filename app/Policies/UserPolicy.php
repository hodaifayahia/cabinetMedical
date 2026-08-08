<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isPlatformAdministrator($user)
            || ($user->cabinet_id !== null
                && $user->can(PermissionName::STAFF_MANAGE->value));
    }

    public function view(User $user, User $model): bool
    {
        return $this->isPlatformAdministrator($user)
            || ($this->sharesCabinetWith($user, $model)
                && ($user->is($model)
                    || $user->can(PermissionName::STAFF_MANAGE->value)));
    }

    public function create(User $user): bool
    {
        return $this->isPlatformAdministrator($user)
            || ($user->cabinet_id !== null
                && $user->can(PermissionName::STAFF_MANAGE->value));
    }

    public function update(User $user, User $model): bool
    {
        if ($this->isPlatformAdministrator($user)) {
            return true;
        }

        if (! $this->sharesCabinetWith($user, $model)) {
            return false;
        }

        if ($model->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
            && ! $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value)) {
            return false;
        }

        return $user->is($model)
            || $user->can(PermissionName::STAFF_MANAGE->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->isPlatformAdministrator($user)
            || ($this->sharesCabinetWith($user, $model)
            && ! $user->is($model)
            && (! $model->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                || $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value))
            && $user->can(PermissionName::STAFF_MANAGE->value));
    }

    private function isPlatformAdministrator(User $user): bool
    {
        return $user->is_platform_admin === true;
    }

    private function sharesCabinetWith(User $user, User $model): bool
    {
        return $user->cabinet_id !== null
            && $model->cabinet_id !== null
            && (int) $user->cabinet_id === (int) $model->cabinet_id;
    }
}
