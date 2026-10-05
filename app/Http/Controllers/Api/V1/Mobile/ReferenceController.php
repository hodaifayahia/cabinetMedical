<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\FacilityType;
use App\Http\Controllers\Controller;
use App\Models\Baladiya;
use App\Models\Wilaya;
use App\Support\FacilityTypeAvailability;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public reference data for the mobile apps: Algerian wilayas and baladiyas
 * plus the bilingual medical specialty catalogue.
 */
class ReferenceController extends Controller
{
    public function wilayas(): JsonResponse
    {
        // Coverage: patients may only pick a region the platform has switched
        // on. The cache key carries the active set's fingerprint so flipping a
        // wilaya in the admin is reflected immediately instead of up to a day
        // later.
        $wilayas = Cache::remember(
            'mobile.reference.wilayas.'.$this->coverageFingerprint(),
            now()->addDay(),
            static fn (): array => Wilaya::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name_fr', 'name_ar'])
                ->map(static fn (Wilaya $wilaya): array => [
                    'code' => $wilaya->code,
                    'name_fr' => $wilaya->name_fr,
                    'name_ar' => $wilaya->name_ar,
                ])
                ->all(),
        );

        return response()->json(['data' => $wilayas]);
    }

    /**
     * A cheap signature of the active wilaya set, so the reference cache turns
     * over the moment coverage changes rather than on its TTL.
     */
    private function coverageFingerprint(): string
    {
        $codes = Wilaya::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code')
            ->implode(',');

        return substr(hash('sha256', $codes), 0, 16);
    }

    public function baladiyas(int $wilaya): JsonResponse
    {
        // An inactive wilaya is indistinguishable from a missing one to the
        // public API: coverage is not something callers get to enumerate.
        abort_unless(
            Wilaya::query()->whereKey($wilaya)->where('is_active', true)->exists(),
            404,
            'Wilaya introuvable.',
        );

        $baladiyas = Baladiya::query()
            ->where('wilaya_code', $wilaya)
            ->where('is_active', true)
            ->orderBy('name_fr')
            ->get(['id', 'wilaya_code', 'name_fr', 'name_ar'])
            ->map(static fn (Baladiya $baladiya): array => [
                'id' => $baladiya->id,
                'wilaya_code' => $baladiya->wilaya_code,
                'name_fr' => $baladiya->name_fr,
                'name_ar' => $baladiya->name_ar,
            ])
            ->all();

        return response()->json(['data' => $baladiyas]);
    }

    /**
     * The specialties the platform admin has switched on — the patient app's
     * specialty filter offers these and nothing else.
     */
    public function specialties(MedicalSpecialtyCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->directory()]);
    }

    /**
     * The kinds of place the platform admin has switched on, in tab order —
     * the patient app's search tabs offer these and nothing else.
     */
    public function facilityTypes(FacilityTypeAvailability $availability): JsonResponse
    {
        return response()->json([
            'data' => array_map(static fn (FacilityType $type): array => [
                'value' => $type->value,
                'label_fr' => $type->label(),
                'label_ar' => $type->labelAr(),
            ], $availability->enabled()),
        ]);
    }
}
