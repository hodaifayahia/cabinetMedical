<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The development platform account (admin@admin.com) used to open the Drclick
 * console on a fresh checkout.
 *
 * A packaged or production database must never contain a known-password
 * administrator, so this seeder is inert unless the same explicit local
 * opt-in that guards the demo cabinet user is set. Real deployments provision
 * their superadmin with `php artisan platform:provision-superadmin`.
 */
class PlatformAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@admin.com';

    public function run(): void
    {
        if (app()->isProduction()
            || ! (bool) config('medismart.development.seed_demo_user', false)) {
            return;
        }

        $password = (string) config('medismart.development.platform_admin_password', '');

        $user = User::query()->whereRaw('LOWER(email) = ?', [self::EMAIL])->first();

        if ($user !== null && $user->is_platform_admin && $user->cabinet_id === null) {
            return;
        }

        if ($user !== null) {
            // The address already belongs to a cabinet identity; converting it
            // silently would move a tenant account into the platform.
            $this->command?->warn(self::EMAIL.' appartient déjà à un compte non-plateforme : aucun changement.');

            return;
        }

        $password = $password !== '' ? $password : Str::password(20);

        User::query()->create([
            'name' => 'Administrateur Drclick',
            'email' => self::EMAIL,
            'password' => $password,
            'is_platform_admin' => true,
            'cabinet_id' => null,
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);

        $this->command?->info('Compte plateforme créé : '.self::EMAIL);
        $this->command?->warn('Mot de passe (affiché une seule fois) : '.$password);
    }
}
