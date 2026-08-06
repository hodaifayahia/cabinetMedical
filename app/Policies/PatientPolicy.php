<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PATIENTS_VIEW->value);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can(PermissionName::PATIENTS_VIEW->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PATIENTS_CREATE->value);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can(PermissionName::PATIENTS_UPDATE->value);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can(PermissionName::PATIENTS_DELETE->value);
    }

    public function viewMedicalRecord(User $user, Patient $patient): bool
    {
        return $user->can(PermissionName::PATIENTS_VIEW_MEDICAL_RECORD->value);
    }
}
