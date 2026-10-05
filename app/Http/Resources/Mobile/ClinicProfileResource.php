<?php

namespace App\Http\Resources\Mobile;

use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\Wilaya;
use App\Support\ClinicPhotos;
use App\Support\Mobile\WeeklySchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Management view of the cabinet's public directory listing, for the staff
 * mobile app. Mirrors the public clinic detail shape and adds the fields only
 * staff may see (is_listed).
 *
 * @mixin CabinetPublicProfile
 */
class ClinicProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cabinet = $this->cabinet;
        $wilaya = $cabinet?->wilaya_code === null
            ? null
            : Wilaya::query()->find($cabinet->wilaya_code);
        $baladiya = $this->baladiya_id === null ? null : $this->baladiya;

        return [
            'clinic' => [
                'id' => $cabinet?->getKey(),
                'name' => $cabinet?->name,
            ],
            'is_listed' => (bool) $this->is_listed,
            'about' => $this->about,
            'address' => $this->address,
            'wilaya' => $wilaya === null ? null : [
                'code' => (int) $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ],
            'baladiya' => $baladiya === null ? null : [
                'id' => $baladiya->getKey(),
                'name_fr' => $baladiya->name_fr,
                'name_ar' => $baladiya->name_ar,
            ],
            'phones' => $this->phones ?? [],
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'photos' => ClinicPhotos::urls($this->photos),
            'working_hours' => WeeklySchedule::forDoctor($this->activeDoctor()),
        ];
    }

    /**
     * The cabinet's active doctor, resolved explicitly by cabinet_id so the
     * shape stays correct regardless of the caller's own scope.
     */
    private function activeDoctor(): ?DoctorProfile
    {
        if ($this->cabinet_id === null) {
            return null;
        }

        return DoctorProfile::withoutCabinetScope()
            ->where('cabinet_id', $this->cabinet_id)
            ->where('is_active', true)
            ->first();
    }
}
