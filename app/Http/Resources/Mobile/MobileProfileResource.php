<?php

namespace App\Http\Resources\Mobile;

use App\Models\User;
use App\Models\Wilaya;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The mobile identity payload of an account: user identifiers plus the
 * demographic patient profile with resolved wilaya/baladiya names. Load
 * `patientProfile.baladiya` before resolving.
 *
 * @mixin User
 */
class MobileProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->patientProfile;
        $baladiya = $profile?->baladiya;
        $wilaya = $profile?->wilaya_code === null
            ? null
            : Wilaya::query()->find($profile->wilaya_code);

        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->mobileRole(),
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'gender' => $profile?->gender?->value,
            'date_of_birth' => $profile?->date_of_birth?->toDateString(),
            'place_of_birth' => $profile?->place_of_birth,
            'wilaya' => $wilaya === null ? null : [
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ],
            'baladiya' => $baladiya === null ? null : [
                'id' => $baladiya->id,
                'name_fr' => $baladiya->name_fr,
                'name_ar' => $baladiya->name_ar,
            ],
        ];
    }
}
