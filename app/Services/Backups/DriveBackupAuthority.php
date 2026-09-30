<?php

namespace App\Services\Backups;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\User;
use App\Services\InstallationMaintenanceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may act on the installation's Google Drive backup.
 *
 * Two levels, both enforced on the server:
 *
 * - manage: see the Drive status and upload history, test the connection,
 *   list, download or delete Drclick archives, send a one-off copy and cancel
 *   a queued upload. Needs `configuration.drive.manage` within the
 *   installation-maintenance boundary.
 * - control: connect, change or disconnect the Google account and set, change
 *   or remove the passphrase of the automatic copies. Reserved to the clinic's
 *   doctor (the Doctor role or the cabinet owner). A permission granted to an
 *   assistant, directly or through the cabinet role matrix, is not enough.
 *
 * On a supervised desktop the machine belongs to one clinic, so its doctor is
 * also inside the boundary, even though tenant users are kept out of these
 * installation-wide tools elsewhere. That holds only while the desktop holds
 * exactly one cabinet: an archive covers the whole installation and must never
 * go to one clinic's Drive with another clinic's records in it.
 *
 * Stopping is never riskier than carrying on, so the doctor whose cabinet
 * holds the Drive grant may always disable the automatic copy or disconnect
 * the account (revoke), even once a second cabinet has appeared.
 */
final class DriveBackupAuthority
{
    public const DENIAL_MESSAGE = 'Seul le médecin du cabinet peut connecter, changer ou déconnecter le compte Google Drive et définir la phrase secrète des envois automatiques.';

    public function __construct(
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
    ) {}

    /**
     * Whether a whole-installation archive may go to the Drive grant of this
     * cabinet settings row: only while the machine holds no other clinic's
     * records. A legacy install without any cabinet is one clinic by
     * construction, and its installation-wide row (no cabinet) stays usable
     * while the machine holds a single cabinet.
     */
    public function installationMayUploadTo(CabinetSetting $settings): bool
    {
        $cabinetIds = $this->cabinetIds();

        return match ($cabinetIds->count()) {
            0 => true,
            1 => $settings->cabinet_id === null
                || (int) $settings->cabinet_id === (int) $cabinetIds->first(),
            default => false,
        };
    }

    /**
     * The doctor may stop what their own cabinet's Drive grant receives
     * (disable the automatic copy, disconnect the account) even while every
     * other Drive action is refused because the machine is shared.
     */
    public function mayRevoke(?User $user): bool
    {
        if ($this->mayControl($user)) {
            return true;
        }

        return $user instanceof User
            && $user->can(PermissionName::CONFIGURATION_DRIVE_MANAGE->value)
            && $this->isDesktopDoctor($user)
            && DriveBackupConnection::query()
                ->whereNotNull('refresh_token')
                ->whereHas('cabinet', fn (Builder $settings) => $settings->where('cabinet_id', $user->cabinet_id))
                ->exists();
    }

    /**
     * A clinic doctor on a supervised desktop that also holds another
     * cabinet: every Drive action is refused, and they should be told why.
     */
    public function isDoctorOnSharedDesktop(?User $user): bool
    {
        return $user instanceof User
            && $user->can(PermissionName::CONFIGURATION_DRIVE_MANAGE->value)
            && $this->isDesktopDoctor($user)
            && $this->cabinetIds()->count() > 1;
    }

    public function mayManage(?User $user): bool
    {
        if (! $user instanceof User
            || ! $user->can(PermissionName::CONFIGURATION_DRIVE_MANAGE->value)) {
            return false;
        }

        return $this->installationMaintenance->allows($user)
            || $this->isDesktopClinicDoctor($user);
    }

    public function mayControl(?User $user): bool
    {
        return $user instanceof User
            && $this->mayManage($user)
            && $this->isClinicDoctor($user);
    }

    /**
     * Control over one cabinet's Drive grant. A doctor scoped to a cabinet may
     * only act on that cabinet's settings row, never on another one.
     */
    public function mayControlCabinet(?User $user, CabinetSetting $cabinet): bool
    {
        if (! $user instanceof User || ! $this->mayControl($user)) {
            return false;
        }

        return $user->cabinet_id === null
            || (int) $cabinet->cabinet_id === (int) $user->cabinet_id;
    }

    public function authorizeManage(?User $user): void
    {
        abort_unless($this->mayManage($user), 403, InstallationMaintenanceAccessService::DENIAL_MESSAGE);
    }

    public function authorizeControl(?User $user): void
    {
        $this->authorizeManage($user);

        abort_unless($this->mayControl($user), 403, self::DENIAL_MESSAGE);
    }

    public function authorizeRevoke(?User $user): void
    {
        abort_unless($this->mayRevoke($user), 403, self::DENIAL_MESSAGE);
    }

    private function isClinicDoctor(User $user): bool
    {
        if ($user->isMobilePatient()) {
            return false;
        }

        if ($user->hasRole(RoleName::DOCTOR->value)) {
            return true;
        }

        return $user->cabinet_id !== null
            && Cabinet::query()
                ->whereKey($user->cabinet_id)
                ->where('owner_user_id', $user->getKey())
                ->exists();
    }

    /** The doctor of the one cabinet this supervised desktop holds. */
    public function isDesktopClinicDoctor(User $user): bool
    {
        if (! $this->isDesktopDoctor($user)) {
            return false;
        }

        $cabinetIds = $this->cabinetIds();

        return $cabinetIds->count() === 1
            && (int) $cabinetIds->first() === (int) $user->cabinet_id;
    }

    /** The doctor of an approved cabinet account on a supervised desktop. */
    private function isDesktopDoctor(User $user): bool
    {
        return (bool) config('medismart.runtime.desktop_supervised', false)
            && ! $user->is_platform_admin
            && $user->cabinet_id !== null
            && $user->isApproved()
            && $this->isClinicDoctor($user);
    }

    /**
     * At most two ids: enough to tell "none", "exactly this one" and "shared".
     *
     * @return Collection<array-key, mixed>
     */
    private function cabinetIds(): Collection
    {
        return Cabinet::query()->orderBy('id')->limit(2)->pluck('id');
    }
}
