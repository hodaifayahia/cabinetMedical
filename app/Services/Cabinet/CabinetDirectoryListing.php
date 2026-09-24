<?php

namespace App\Services\Cabinet;

use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;

/**
 * The one place that shows or hides a cabinet in the patient app's directory.
 *
 * Every cabinet is listed from the moment it is provisioned, so a doctor
 * reaches the app as soon as the cabinet is activated; discovery still hides
 * anything that is not active. The row is written with an explicit cabinet_id
 * because the BelongsToCabinet creating hook assigns nothing for a guest
 * registrant or a platform admin.
 */
class CabinetDirectoryListing
{
    public function setListed(Cabinet $cabinet, bool $isListed): CabinetPublicProfile
    {
        $profile = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->first() ?? (new CabinetPublicProfile)->forceFill(['cabinet_id' => $cabinet->getKey()]);

        $profile->forceFill(['is_listed' => $isListed])->save();

        return $profile;
    }

    public function isListed(Cabinet $cabinet): bool
    {
        return CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->where('is_listed', true)
            ->exists();
    }
}
