<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Baladiya;
use App\Models\Wilaya;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
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

    public function specialties(MedicalSpecialtyCatalog $catalog): JsonResponse
    {
        $arabic = SpecialtyArabicLabels::map();

        $specialties = collect($catalog->labels())
            ->map(static function (string $labelFr) use ($catalog, $arabic): array {
                $code = $catalog->codeFor($labelFr);

                return [
                    'code' => $code,
                    'label_fr' => $labelFr,
                    'label_ar' => $arabic[$code] ?? $labelFr,
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $specialties]);
    }
}
