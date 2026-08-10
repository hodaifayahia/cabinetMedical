<?php

namespace App\Policies;

use App\Models\Encounter;
use App\Models\User;

class EncounterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('encounters.view');
    }

    public function view(User $user, Encounter $encounter): bool
    {
        return $this->sharesCabinetWith($user, $encounter)
            && $user->can('encounters.view');
    }

    public function create(User $user): bool
    {
        return $user->can('encounters.create');
    }

    public function update(User $user, Encounter $encounter): bool
    {
        return $this->sharesCabinetWith($user, $encounter)
            && $user->can('encounters.update')
            && ! $encounter->isSigned();
    }

    public function sign(User $user, Encounter $encounter): bool
    {
        return $this->sharesCabinetWith($user, $encounter)
            && $user->can('encounters.sign');
    }

    public function amend(User $user, Encounter $encounter): bool
    {
        return $this->sharesCabinetWith($user, $encounter)
            && $user->can('encounters.amend')
            && $encounter->isSigned();
    }

    private function sharesCabinetWith(User $user, Encounter $encounter): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $encounterCabinetId = $encounter->getAttribute('cabinet_id');

        return $user->cabinet_id === null
            ? $encounterCabinetId === null
            : $encounterCabinetId !== null
                && (int) $user->cabinet_id === (int) $encounterCabinetId;
    }
}
