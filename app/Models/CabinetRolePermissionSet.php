<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string>|null $permissions
 *
 * A complete, cabinet-specific permission allow-list for one canonical role.
 *
 * The row itself is significant: an empty permissions array means the cabinet
 * explicitly revoked every permission from that role. When no row exists, the
 * globally seeded Spatie role remains the backwards-compatible default.
 */
#[Fillable(['cabinet_id', 'role_name', 'permissions'])]
class CabinetRolePermissionSet extends Model
{
    use BelongsToCabinet;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->permissions ?? [];
    }
}
