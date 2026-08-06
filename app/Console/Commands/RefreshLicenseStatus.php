<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Services\LicenseActivationService;
use App\Services\LicenseService;
use Illuminate\Console\Command;
use Throwable;

final class RefreshLicenseStatus extends Command
{
    protected $signature = 'medismart:license:refresh {--force : Ignore l’intervalle minimal de six heures}';

    protected $description = 'Actualise la licence auprès du serveur et vérifie le nouveau certificat signé';

    public function handle(
        LicenseActivationService $activation,
        LicenseService $licenses,
    ): int {
        if (! $activation->status()['refresh_configured']) {
            $this->components->info('Actualisation ignorée : serveur de licences non configuré.');

            return self::SUCCESS;
        }

        $license = License::query()->latest('id')->first();

        if ($license === null) {
            $this->components->info('Actualisation ignorée : aucune licence active sur cette installation.');

            return self::SUCCESS;
        }

        $clockWarning = $licenses->status()['clock_warning'];

        if (! $this->option('force')
            && ! $clockWarning
            && $license->last_verified_at !== null
            && $license->last_verified_at->isAfter(now()->subHours(6))) {
            $this->components->info('Actualisation ignorée : la dernière vérification est encore récente.');

            return self::SUCCESS;
        }

        try {
            $activation->refresh();
        } catch (Throwable) {
            $this->components->warn('Serveur indisponible ou réponse invalide; le certificat local reste inchangé.');

            return self::FAILURE;
        }

        $this->components->info('Licence actualisée et signature vérifiée.');

        return self::SUCCESS;
    }
}
