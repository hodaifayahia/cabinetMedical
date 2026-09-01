<?php

namespace App\Http\Resources\Mobile;

use App\Models\DoctorProfile;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public directory card for one listed doctor. The underlying model is a
 * DoctorProfile row that the directory query augments with clinic_* columns
 * and the clinic_wilaya / clinic_baladiya lookup arrays.
 *
 * @mixin DoctorProfile
 *
 * @property int $clinic_id
 * @property string $clinic_name
 * @property array{code: int, name_fr: string, name_ar: string}|null $clinic_wilaya
 * @property array{id: int, name_fr: string, name_ar: string}|null $clinic_baladiya
 * @property string|null $clinic_address
 */
class DoctorCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->doctor_name ?? $this->user?->name,
            'specialty' => $this->specialtyPayload(),
            'clinic' => [
                'id' => $this->clinic_id,
                'name' => $this->clinic_name,
                'wilaya' => $this->clinic_wilaya,
                'baladiya' => $this->clinic_baladiya,
                'address' => $this->clinic_address,
            ],
        ];
    }

    /**
     * @return array{code: string, label_fr: string, label_ar: string}|null
     */
    private function specialtyPayload(): ?array
    {
        $code = $this->specialty_code;

        if ($code === null) {
            return null;
        }

        $labelFr = app(MedicalSpecialtyCatalog::class)->display($this->specialty, $code);

        return [
            'code' => $code,
            'label_fr' => $labelFr,
            'label_ar' => SpecialtyArabicLabels::labelFor($code) ?? $labelFr,
        ];
    }
}
