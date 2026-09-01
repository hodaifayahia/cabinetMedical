<?php

namespace App\Http\Resources\Mobile\Admin;

use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Trimmed clinic row for the platform list screen. The listing query attaches
 * the `wilaya_payload`, `is_listed_flag` and `owner_name` attributes in bulk,
 * so rendering a page costs no extra query per row.
 *
 * @mixin Cabinet
 *
 * @property array{code: int, name_fr: string, name_ar: string}|null $wilaya_payload
 * @property bool $is_listed_flag
 * @property string|null $owner_name
 */
class AdminCabinetListItemResource extends JsonResource
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
            'status_label' => $this->status->label(),
            'specialization' => $this->specialization,
            'wilaya' => $this->wilaya_payload,
            'is_listed' => (bool) $this->is_listed_flag,
            'owner_name' => $this->owner_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
        ];
    }
}
