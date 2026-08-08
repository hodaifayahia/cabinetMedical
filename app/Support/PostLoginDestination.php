<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class PostLoginDestination
{
    public static function for(?Authenticatable $user): string
    {
        return $user instanceof User && $user->is_platform_admin
            ? '/admin'
            : '/dashboard';
    }
}
