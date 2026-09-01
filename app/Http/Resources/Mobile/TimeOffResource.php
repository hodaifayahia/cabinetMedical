<?php

namespace App\Http\Resources\Mobile;

use App\Models\DoctorTimeOff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorTimeOff
 */
class TimeOffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'is_all_day' => (bool) $this->is_all_day,
            'reason' => $this->reason,
        ];
    }
}
