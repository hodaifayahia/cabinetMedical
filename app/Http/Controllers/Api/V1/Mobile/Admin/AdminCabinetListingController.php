<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Http\Requests\Api\Mobile\Admin\UpdateAdminCabinetListingRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * Platform back office: show or hide a clinic in the public mobile directory.
 *
 * Visibility is one boolean on the cabinet's public profile — the same row the
 * clinic's own staff edit from the staff-mobile app — so an admin toggling it
 * here and a doctor toggling it there converge on one source of truth. Note
 * that a hidden clinic is still fully operational; only discovery changes.
 */
class AdminCabinetListingController extends AdminController
{
    public function update(UpdateAdminCabinetListingRequest $request, int $cabinet): JsonResponse
    {
        $target = $this->findCabinet($cabinet);
        $isListed = (bool) $request->validated('is_listed');

        $this->setCabinetListed($target, $isListed);

        AuditLog::record('admin.cabinet_listing_updated', $target, [
            'is_listed' => $isListed,
        ], $request->user()?->getKey());

        return $this->cabinetDetail($target)->response();
    }
}
