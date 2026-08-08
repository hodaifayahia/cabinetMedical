<?php

namespace App\Services;

use App\Models\User;

/**
 * Authorizes operations that act on the whole installation rather than on a
 * single cabinet. Tenant users must never reach these legacy desktop tools:
 * their archives, native settings, and machine licence are shared globally.
 */
final class InstallationMaintenanceAccessService
{
    public const DENIAL_MESSAGE = 'Cette fonction de maintenance globale est réservée à l’administration de la plateforme.';

    public function allows(?User $user): bool
    {
        return $user instanceof User
            && ($user->is_platform_admin || $user->cabinet_id === null);
    }

    public function authorize(?User $user): void
    {
        abort_unless($this->allows($user), 403, self::DENIAL_MESSAGE);
    }
}
