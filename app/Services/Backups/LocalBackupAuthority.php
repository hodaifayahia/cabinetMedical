<?php

namespace App\Services\Backups;

use App\Enums\PermissionName;
use App\Models\User;
use App\Services\InstallationMaintenanceAccessService;

/**
 * Who may act on the local backups of this installation: see the schedule,
 * change its three times and the retention, save a backup now and export one.
 *
 * Local backups cover the whole installation, so they stay inside the
 * installation-maintenance boundary. On a supervised desktop that holds a
 * single cabinet the machine is that clinic's, and its doctor is inside the
 * boundary too, exactly as for the Drive backup (see DriveBackupAuthority).
 */
final class LocalBackupAuthority
{
    public function __construct(
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
        private readonly DriveBackupAuthority $driveAuthority,
    ) {}

    public function mayManage(?User $user): bool
    {
        return $user instanceof User
            && $user->can(PermissionName::CONFIGURATION_BACKUPS_MANAGE->value)
            && ($this->installationMaintenance->allows($user)
                || $this->driveAuthority->isDesktopClinicDoctor($user));
    }

    public function authorizeManage(?User $user): void
    {
        abort_unless($this->mayManage($user), 403, InstallationMaintenanceAccessService::DENIAL_MESSAGE);
    }
}
