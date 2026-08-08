<?php

namespace App\Http\Resources;

use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cabinet
 */
class CabinetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'specialization' => $this->specialization,
            'wilaya' => [
                'code' => $this->wilaya_code,
                'name' => $this->wilaya_name,
            ],
            'license' => $this->whenLoaded('license', fn (): ?array => $this->license === null ? null : [
                'plan' => $this->license->plan?->value,
            'plan_label' => $this->license->typeLabel(),
                'status' => $this->license->effectiveStatus(),
                'status_label' => $this->license->effectiveStatusLabel(),
                'expires_at' => $this->license->expires_at?->toIso8601String(),
            ]),
        ];
    }
}
