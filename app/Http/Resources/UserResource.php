<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_platform_admin' => $this->is_platform_admin,
            'approved' => $this->isApproved(),
            'cabinet' => $this->whenLoaded('cabinet', fn () => $this->cabinet ? new CabinetResource($this->cabinet) : null),
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
