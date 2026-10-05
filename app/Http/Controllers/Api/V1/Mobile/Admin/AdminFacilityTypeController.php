<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\FacilityType;
use App\Models\AuditLog;
use App\Support\FacilityTypeAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform back office: which kinds of place the patient app offers —
 * doctors' practices, clinics, imaging centres.
 *
 * Switching a kind off removes its search tab and makes its cabinets
 * unreachable from the public API (directory, clinic page, availability,
 * booking). The cabinets keep running for their own staff. Each row carries
 * its listed-cabinet count so the admin sees what switching it off would hide.
 *
 * At least one kind always stays on: with none, the patient app would have
 * nothing to search.
 */
class AdminFacilityTypeController extends AdminController
{
    public function index(FacilityTypeAvailability $availability): JsonResponse
    {
        $counts = $this->listedCabinetCounts();

        return response()->json([
            'data' => array_map(
                fn (FacilityType $type): array => $this->row($type, $availability, $counts),
                FacilityType::cases(),
            ),
        ]);
    }

    public function update(Request $request, string $type, FacilityTypeAvailability $availability): JsonResponse
    {
        $target = FacilityType::tryFrom($type);

        abort_if($target === null, 404, 'Type d\'établissement introuvable.');

        $isActive = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        if (! $isActive && $availability->enabled() === [$target]) {
            throw ValidationException::withMessages([
                'is_active' => 'Au moins un type d\'établissement doit rester actif.',
            ]);
        }

        $availability->setEnabled($target, $isActive);

        AuditLog::record('admin.facility_type_updated', null, [
            'facility_type' => $target->value,
            'is_active' => $isActive,
        ], $request->user()?->getKey());

        return response()->json([
            'data' => $this->row($target, $availability, $this->listedCabinetCounts()),
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{value: string, label_fr: string, label_ar: string, is_active: bool, clinics: int}
     */
    private function row(FacilityType $type, FacilityTypeAvailability $availability, array $counts): array
    {
        return [
            'value' => $type->value,
            'label_fr' => $type->label(),
            'label_ar' => $type->labelAr(),
            'is_active' => $availability->isEnabled($type),
            'clinics' => $counts[$type->value] ?? 0,
        ];
    }

    /**
     * Listed cabinets per kind, whatever their status, matching what the
     * coverage screen counts per region.
     *
     * @return array<string, int>
     */
    private function listedCabinetCounts(): array
    {
        return DB::table('cabinets')
            ->join('cabinet_public_profiles', 'cabinet_public_profiles.cabinet_id', '=', 'cabinets.id')
            ->where('cabinet_public_profiles.is_listed', true)
            ->groupBy('cabinets.facility_type')
            ->selectRaw('cabinets.facility_type as type, count(*) as total')
            ->pluck('total', 'type')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }
}
