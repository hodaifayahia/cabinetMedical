<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\FacilityType;
use App\Http\Requests\Api\Mobile\Admin\UpdateAdminCabinetListingRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * Reclassify a cabinet as a doctor's practice, a clinic or an imaging
     * centre. This is what the patient app's three search tabs filter on, so
     * the change moves the cabinet between tabs immediately.
     */
    public function updateFacilityType(Request $request, int $cabinet): JsonResponse
    {
        $data = $request->validate([
            'facility_type' => ['required', 'string', Rule::enum(FacilityType::class)],
        ]);

        $target = $this->findCabinet($cabinet);
        $type = FacilityType::from($data['facility_type']);

        $target->forceFill(['facility_type' => $type])->save();

        AuditLog::record('admin.cabinet_facility_type_updated', $target, [
            'facility_type' => $type->value,
        ], $request->user()?->getKey());

        return $this->cabinetDetail($target->refresh())->response();
    }
}
