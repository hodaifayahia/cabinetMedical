<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\CabinetStatus;
use App\Enums\FacilityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ClinicDetailResource;
use App\Http\Resources\Mobile\DoctorCardResource;
use App\Models\Baladiya;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Wilaya;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Public doctor discovery. Only active doctors of active cabinets with a
 * listed public profile are ever visible; everything else is a 404 or simply
 * absent. Queries bypass the cabinet global scope on purpose so the results
 * are identical for anonymous visitors, patient tokens and staff tokens.
 */
class DoctorDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'wilaya_code' => ['sometimes', 'integer', 'between:1,58'],
            'baladiya_id' => ['sometimes', 'integer', 'min:1'],
            'specialty' => ['sometimes', 'string', 'max:100'],
            'facility_type' => ['sometimes', 'string', Rule::enum(FacilityType::class)],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $doctors = DoctorProfile::withoutCabinetScope()
            ->join('cabinets', 'cabinets.id', '=', 'doctor_profiles.cabinet_id')
            ->join('cabinet_public_profiles', 'cabinet_public_profiles.cabinet_id', '=', 'doctor_profiles.cabinet_id')
            ->join('wilayas', 'wilayas.code', '=', 'cabinets.wilaya_code')
            ->where('doctor_profiles.is_active', true)
            ->where('cabinets.status', CabinetStatus::ACTIVE->value)
            ->where('cabinet_public_profiles.is_listed', true)
            // Coverage: a practice in a region the platform has not switched on
            // is invisible, however complete its own profile is.
            ->where('wilayas.is_active', true)
            ->where(static fn (Builder $covered) => $covered
                ->whereNull('cabinet_public_profiles.baladiya_id')
                ->orWhereExists(static fn ($exists) => $exists
                    ->selectRaw('1')
                    ->from('baladiyas')
                    ->whereColumn('baladiyas.id', 'cabinet_public_profiles.baladiya_id')
                    ->where('baladiyas.is_active', true)))
            ->when(
                isset($validated['facility_type']),
                static fn (Builder $query) => $query->where('cabinets.facility_type', $validated['facility_type']),
            )
            ->when(
                isset($validated['wilaya_code']),
                static fn (Builder $query) => $query->where('cabinets.wilaya_code', $validated['wilaya_code']),
            )
            ->when(
                isset($validated['baladiya_id']),
                static fn (Builder $query) => $query->where('cabinet_public_profiles.baladiya_id', $validated['baladiya_id']),
            )
            ->when(
                isset($validated['specialty']),
                // Older profiles store a French-derived code ("pediatrie"), so
                // match every variant of the chosen specialty.
                static fn (Builder $query) => $query->whereIn(
                    'doctor_profiles.specialty_code',
                    app(MedicalSpecialtyCatalog::class)->matchingCodes($validated['specialty']),
                ),
            )
            ->when(
                filled($validated['q'] ?? null),
                static fn (Builder $query) => $query->where(static function (Builder $match) use ($validated): void {
                    $term = '%'.$validated['q'].'%';

                    $match->where('doctor_profiles.doctor_name', 'like', $term)
                        ->orWhere('cabinets.name', 'like', $term);
                }),
            )
            ->orderBy('doctor_profiles.id')
            ->select([
                'doctor_profiles.*',
                'cabinets.id as clinic_id',
                'cabinets.name as clinic_name',
                'cabinets.facility_type as clinic_facility_type',
                'cabinets.wilaya_code as clinic_wilaya_code',
                'cabinet_public_profiles.address as clinic_address',
                'cabinet_public_profiles.baladiya_id as clinic_baladiya_id',
            ])
            ->with('user:id,name')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        $this->attachClinicGeo($doctors->getCollection());

        return DoctorCardResource::collection($doctors);
    }

    public function show(int $cabinet): ClinicDetailResource
    {
        /** @var Cabinet|null $clinic */
        $clinic = Cabinet::query()
            ->whereKey($cabinet)
            ->where('status', CabinetStatus::ACTIVE->value)
            ->first();

        if ($clinic === null) {
            abort(404, 'Cabinet introuvable.');
        }

        $profile = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $clinic->getKey())
            ->where('is_listed', true)
            ->first();

        if (! $profile instanceof CabinetPublicProfile) {
            abort(404, 'Cabinet introuvable.');
        }

        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::withoutCabinetScope()
            ->with('user:id,name')
            ->where('cabinet_id', $clinic->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $schedules = $doctor === null
            ? new Collection
            : DoctorSchedule::withoutCabinetScope()
                ->where('doctor_id', $doctor->getKey())
                ->where('is_active', true)
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get()
                ->toBase();

        $wilaya = $clinic->wilaya_code === null
            ? null
            : Wilaya::query()->find($clinic->wilaya_code);

        $baladiya = $profile->baladiya_id === null
            ? null
            : Baladiya::query()->find($profile->baladiya_id);

        return new ClinicDetailResource($clinic, $profile, $doctor, $schedules, $wilaya, $baladiya);
    }

    /**
     * Attach the wilaya/baladiya name payloads for the page in two lookup
     * queries instead of one pair per card.
     *
     * @param  Collection<int, DoctorProfile>  $rows
     */
    private function attachClinicGeo(Collection $rows): void
    {
        $wilayas = Wilaya::query()
            ->whereIn('code', $rows->pluck('clinic_wilaya_code')->filter()->unique())
            ->get()
            ->keyBy('code');

        $baladiyas = Baladiya::query()
            ->whereIn('id', $rows->pluck('clinic_baladiya_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $rows->each(static function (DoctorProfile $row) use ($wilayas, $baladiyas): void {
            /** @var Wilaya|null $wilaya */
            $wilaya = $wilayas->get($row->getAttribute('clinic_wilaya_code'));
            /** @var Baladiya|null $baladiya */
            $baladiya = $baladiyas->get($row->getAttribute('clinic_baladiya_id'));

            $row->setAttribute('clinic_wilaya', $wilaya === null ? null : [
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ]);

            $row->setAttribute('clinic_baladiya', $baladiya === null ? null : [
                'id' => $baladiya->id,
                'name_fr' => $baladiya->name_fr,
                'name_ar' => $baladiya->name_ar,
            ]);
        });
    }
}
