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
        return $user->can('encounters.view');
    }

    public function create(User $user): bool
    {
        return $user->can('encounters.create');
    }

    public function update(User $user, Encounter $encounter): bool
    {
        return $user->can('encounters.update') && ! $encounter->isSigned();
    }

    public function sign(User $user, Encounter $encounter): bool
    {
        return $user->can('encounters.sign');
    }

    public function amend(User $user, Encounter $encounter): bool
    {
        return $user->can('encounters.amend') && $encounter->isSigned();
    }
}
