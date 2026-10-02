<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\Cabinet;
use App\Models\User;

/**
 * Controls who may install a signed update on this desktop.
 *
 * An update restarts the whole desktop installation, so browser accounts
 * outside the installation cannot authorize it. A supervised desktop holding
 * exactly one cabinet may be updated by any approved, non-patient account in
 * that cabinet; the signed artifact and verified pre-update backup still
 * protect the installation. Unscoped installations keep the existing
 * connectivity permission boundary.
 */
final class DesktopUpdateInstallAuthority
{
    public function __construct(
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
    ) {}

    public function allows(?User $user): bool
    {
        if (! $user instanceof User || $user->isMobilePatient()) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        if ($user->cabinet_id === null) {
            return $this->installationMaintenance->allows($user)
                && $user->can(PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value);
        }

        if (! $user->isApproved()
            || ! (bool) config('medismart.runtime.desktop_supervised', false)) {
            return false;
        }

        // Cabinet itself is a platform model and has no tenant scope.
        $cabinetIds = Cabinet::query()->orderBy('id')->limit(2)->pluck('id');

        return $cabinetIds->count() === 1
            && (int) $cabinetIds->first() === (int) $user->cabinet_id;
    }

    public function authorize(?User $user): void
    {
        abort_unless($this->allows($user), 403, 'Ce compte ne peut pas mettre à jour cette installation.');
    }
}
