<?php

namespace App\Http\Resources\Mobile;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The patient-facing prescription payload: the prescription content plus the
 * clinic name and the display name of the dossier it was written for.
 *
 * @mixin Prescription
 */
class MobilePrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescribed_at' => $this->prescribed_at?->toIso8601String(),
            'items' => $this->items ?? [],
            'notes' => $this->notes,
            'clinic' => [
                'name' => $this->cabinet?->name,
            ],
            'patient_display_name' => $this->patient?->full_name,
        ];
    }
}
