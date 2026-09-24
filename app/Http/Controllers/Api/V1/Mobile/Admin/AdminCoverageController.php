<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Models\AuditLog;
use App\Models\Baladiya;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform back office: which parts of the country the mobile app covers.
 *
 * The public reference endpoints only offer ACTIVE wilayas and baladiyas, and
 * discovery only surfaces practices inside them, so this is the switch that
 * decides where patients can search at all. Everything ships active; an admin
 * narrows coverage rather than opening it.
 *
 * Turning a wilaya off hides its communes and its clinics too — that is the
 * point of the control — so each row carries the counts an admin needs to see
 * the blast radius before flipping it.
 */
class AdminCoverageController extends AdminController
{
    /** Every wilaya with its coverage state and what it currently holds. */
    public function wilayas(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $rows = Wilaya::query()
            ->when($search !== '', static fn ($query) => $query->where(
                static fn ($match) => $match
                    ->where('name_fr', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%"),
            ))
            ->withCount([
                'baladiyas',
                'baladiyas as active_baladiyas_count' => static fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('code')
            ->get();

        $clinicCounts = $this->clinicCountsByWilaya();

        return response()->json([
            'data' => $rows->map(static fn (Wilaya $wilaya): array => [
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
                'is_active' => $wilaya->is_active,
                'baladiyas_total' => (int) $wilaya->getAttribute('baladiyas_count'),
                'baladiyas_active' => (int) $wilaya->getAttribute('active_baladiyas_count'),
                'clinics' => (int) ($clinicCounts[$wilaya->code] ?? 0),
            ])->all(),
        ]);
    }

    /** Switch a whole wilaya on or off. */
    public function updateWilaya(Request $request, int $wilaya): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            // Opt-in convenience: match every commune to the wilaya's new
            // state in the same call, so enabling a region does not leave the
            // admin toggling hundreds of rows by hand.
            'cascade' => ['sometimes', 'boolean'],
        ]);

        $target = Wilaya::query()->whereKey($wilaya)->first();

        abort_if($target === null, 404, 'Wilaya introuvable.');

        $isActive = (bool) $data['is_active'];
        $cascade = (bool) ($data['cascade'] ?? false);

        DB::transaction(static function () use ($target, $isActive, $cascade): void {
            $target->forceFill(['is_active' => $isActive])->save();

            if ($cascade) {
                Baladiya::query()
                    ->where('wilaya_code', $target->code)
                    ->update(['is_active' => $isActive]);
            }
        });

        AuditLog::record('admin.coverage_wilaya_updated', $target, [
            'wilaya_code' => $target->code,
            'is_active' => $isActive,
            'cascade' => $cascade,
        ], $request->user()?->getKey());

        return response()->json([
            'data' => [
                'code' => $target->code,
                'name_fr' => $target->name_fr,
                'name_ar' => $target->name_ar,
                'is_active' => $isActive,
                'cascaded' => $cascade,
            ],
        ]);
    }

    /** The communes of one wilaya, active or not. */
    public function baladiyas(Request $request, int $wilaya): JsonResponse
    {
        abort_unless(Wilaya::query()->whereKey($wilaya)->exists(), 404, 'Wilaya introuvable.');

        $search = trim((string) $request->query('q', ''));

        $rows = Baladiya::query()
            ->where('wilaya_code', $wilaya)
            ->when($search !== '', static fn ($query) => $query->where(
                static fn ($match) => $match
                    ->where('name_fr', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%"),
            ))
            ->orderBy('name_fr')
            ->get(['id', 'wilaya_code', 'name_fr', 'name_ar', 'is_active']);

        return response()->json([
            'data' => $rows->map(static fn (Baladiya $baladiya): array => [
                'id' => $baladiya->id,
                'wilaya_code' => $baladiya->wilaya_code,
                'name_fr' => $baladiya->name_fr,
                'name_ar' => $baladiya->name_ar,
                'is_active' => $baladiya->is_active,
            ])->all(),
        ]);
    }

    /** Switch one commune on or off. */
    public function updateBaladiya(Request $request, int $baladiya): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $target = Baladiya::query()->whereKey($baladiya)->first();

        abort_if($target === null, 404, 'Baladiya introuvable.');

        $isActive = (bool) $data['is_active'];
        $target->forceFill(['is_active' => $isActive])->save();

        AuditLog::record('admin.coverage_baladiya_updated', $target, [
            'baladiya_id' => $target->getKey(),
            'wilaya_code' => $target->wilaya_code,
            'is_active' => $isActive,
        ], $request->user()?->getKey());

        return response()->json([
            'data' => [
                'id' => $target->getKey(),
                'wilaya_code' => $target->wilaya_code,
                'name_fr' => $target->name_fr,
                'name_ar' => $target->name_ar,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * Listed clinics per wilaya, so an admin can see what switching a region
     * off would hide.
     *
     * @return array<int, int>
     */
    private function clinicCountsByWilaya(): array
    {
        return DB::table('cabinets')
            ->join('cabinet_public_profiles', 'cabinet_public_profiles.cabinet_id', '=', 'cabinets.id')
            ->where('cabinet_public_profiles.is_listed', true)
            ->whereNotNull('cabinets.wilaya_code')
            ->groupBy('cabinets.wilaya_code')
            ->selectRaw('cabinets.wilaya_code as code, count(*) as total')
            ->pluck('total', 'code')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }
}
