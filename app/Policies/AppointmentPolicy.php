<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::APPOINTMENTS_VIEW->value);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->sharesCabinetWith($user, $appointment)
            && $user->can(PermissionName::APPOINTMENTS_VIEW->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::APPOINTMENTS_CREATE->value);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->sharesCabinetWith($user, $appointment)
            && $user->can(PermissionName::APPOINTMENTS_UPDATE->value);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->sharesCabinetWith($user, $appointment)
            && $user->can(PermissionName::APPOINTMENTS_CANCEL->value);
    }

    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $this->sharesCabinetWith($user, $appointment)
            && $user->can(PermissionName::APPOINTMENTS_CHECK_IN->value);
    }

    private function sharesCabinetWith(User $user, Appointment $appointment): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $appointmentCabinetId = $appointment->getAttribute('cabinet_id');

        return $user->cabinet_id === null
            ? $appointmentCabinetId === null
            : $appointmentCabinetId !== null
                && (int) $user->cabinet_id === (int) $appointmentCabinetId;
    }
}
