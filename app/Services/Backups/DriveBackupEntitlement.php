<?php

namespace App\Services\Backups;

use App\Models\Cabinet;
use App\Models\License;
use App\Services\LicenseService;

/**
 * Whether this installation is entitled to the Google Drive backup.
 *
 * A signed machine licence carrying the feature grants it anywhere. A clinic
 * desktop, however, is activated through the cabinet's hosted plan and never
 * receives a machine certificate, while the Drive backup is mandatory there.
 * On a supervised desktop the machine's one cabinet holding an active hosted
 * plan is therefore entitled too. Every Drive gate reads this one answer so
 * the page, the endpoints and the scheduled copy cannot disagree.
 */
final class DriveBackupEntitlement
{
    public const FEATURE = 'google_drive_backup';

    public function __construct(private readonly LicenseService $licenses) {}

    public function granted(): bool
    {
        if ($this->licenses->featureEnabled(self::FEATURE)) {
            return true;
        }

        if (! (bool) config('medismart.runtime.desktop_supervised', false)) {
            return false;
        }

        // Two rows are enough to tell a single-clinic desktop from a shared one.
        $cabinets = Cabinet::query()->with('license')->orderBy('id')->limit(2)->get();
        $cabinet = $cabinets->count() === 1 ? $cabinets->first() : null;
        $license = $cabinet?->license;

        return $cabinet instanceof Cabinet
            && $cabinet->isActive()
            && $license instanceof License
            && $license->isHostedEntitlement()
            && $license->effectiveStatus() === 'active';
    }
}
