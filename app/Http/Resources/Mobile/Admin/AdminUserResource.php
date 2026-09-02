<?php

namespace App\Http\Resources\Mobile\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as the platform back office sees it. Deliberately narrow: the
 * password hash, the local PIN digest, the two-factor secret and every API
 * token stay out of the payload, and there is no is_platform_admin flag to
 * read back — superadmins are never listed or created through the API.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
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
            'phone' => $this->phone,
            'role' => $this->mobileRole(),
            'cabinet_id' => $this->cabinet_id,
            'approved' => $this->approved_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
