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
        $wilayas = Cache::remember(
            'mobile.reference.wilayas',
            now()->addDay(),
            static fn (): array => Wilaya::query()
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

    public function baladiyas(int $wilaya): JsonResponse
    {
        abort_unless(
            Wilaya::query()->whereKey($wilaya)->exists(),
            404,
            'Wilaya introuvable.',
        );

        $baladiyas = Baladiya::query()
            ->where('wilaya_code', $wilaya)
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
