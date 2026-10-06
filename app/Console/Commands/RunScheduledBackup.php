<?php

namespace App\Console\Commands;

use App\Backups\BackupSchedule;
use App\Backups\LocalRestorePointRunner;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Services\ApplicationSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class RunScheduledBackup extends Command
{
    protected $signature = 'medismart:backup:scheduled {--force : Crée une sauvegarde même si aucune échéance n’est due}';

    protected $description = 'Crée une sauvegarde locale vérifiée à chacune des trois heures planifiées de la journée';

    public function handle(ApplicationSettingService $settings, LocalRestorePointRunner $runner): int
    {
        $forced = (bool) $this->option('force');

        // Local backups are mandatory on a supervised desktop, so there is no
        // setting that switches them off: only the supervisor can.
        if (! $forced
            && (! (bool) config('medismart.runtime.desktop_supervised', false)
                || config('medismart.runtime.scheduler_status') !== 'active')) {
            $this->components->info('Sauvegarde ignorée : scheduler desktop non supervisé.');

            return self::SUCCESS;
        }

        $schedule = BackupSchedule::fromSetting($settings->get(Setting::BACKUP_SCHEDULE_TIMES));
        $scheduledFor = $schedule->latestDueSlot(CarbonImmutable::now());

        if (! $forced && $runner->hasRestorePointSince($scheduledFor)) {
            $this->components->info('Sauvegarde ignorée : une archive vérifiée existe déjà pour cette échéance.');

            return self::SUCCESS;
        }

        try {
            $result = $runner->run(LocalRestorePointRunner::TRIGGER_SCHEDULED, $scheduledFor);
        } catch (Throwable) {
            $this->components->error('La sauvegarde planifiée a échoué; aucune réussite n’a été enregistrée.');

            return self::FAILURE;
        }

        if ($result === null) {
            $this->components->info('Sauvegarde ignorée : une création est déjà en cours.');

            return self::SUCCESS;
        }

        if (! $result['retention']) {
            $this->components->warn('La sauvegarde est valide, mais la rétention locale a été ignorée par sécurité.');
        }

        if ($result['copy']['status'] === 'failed') {
            $this->components->warn(
                'La sauvegarde est valide, mais sa copie vers le dossier choisi a échoué : '.$result['copy']['message'],
            );
        }

        if ($result['drive'] === 'queued') {
            $this->components->info('Copie chiffrée ajoutée à la file d’envoi Google Drive.');
        } elseif ($result['drive'] === 'failed') {
            $this->components->warn(
                'La sauvegarde locale est valide, mais sa copie Google Drive n’a pas pu être préparée.',
            );
        }

        $this->components->info('Sauvegarde planifiée créée et vérifiée.');

        return self::SUCCESS;
    }
}
