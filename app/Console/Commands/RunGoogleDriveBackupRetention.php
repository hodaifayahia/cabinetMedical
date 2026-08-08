<?php

namespace App\Console\Commands;

use App\Backups\RemoteBackupRetentionManager;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use Illuminate\Console\Command;
use Throwable;

final class RunGoogleDriveBackupRetention extends Command
{
    protected $signature = 'medismart:backup:drive-retention
        {--force : Exécute la rétention même hors du scheduler desktop ou si elle est désactivée}';

    protected $description = 'Applique la rétention sécurisée aux sauvegardes Drclick vérifiées sur Google Drive';

    public function handle(RemoteBackupRetentionManager $retention): int
    {
        $forced = (bool) $this->option('force');

        try {
            $enabled = $retention->configuredEnabled();

            if (! $forced && ! $enabled) {
                $this->components->info('Rétention Google Drive désactivée.');

                return self::SUCCESS;
            }
        } catch (Throwable) {
            $this->recordCommandFailure('invalid_retention_configuration');
            $this->components->error('La configuration de rétention Google Drive est invalide.');

            return self::FAILURE;
        }

        if (! $forced
            && (! (bool) config('medismart.runtime.desktop_supervised', false)
                || config('medismart.runtime.scheduler_status') !== 'active')) {
            $this->components->info('Rétention Google Drive ignorée : scheduler desktop non supervisé.');

            return self::SUCCESS;
        }

        $cabinetIds = DriveBackupConnection::query()
            ->whereNotNull('refresh_token')
            ->orderBy('cabinet_setting_id')
            ->pluck('cabinet_setting_id')
            ->unique()
            ->values();

        if ($cabinetIds->isEmpty()) {
            $this->components->info('Rétention Google Drive ignorée : aucun compte connecté.');

            return self::SUCCESS;
        }

        $failed = false;
        $deletedCount = 0;

        foreach ($cabinetIds as $cabinetId) {
            $cabinet = CabinetSetting::query()->find((int) $cabinetId);

            if ($cabinet === null) {
                $failed = true;
                $this->recordCommandFailure('missing_cabinet');

                continue;
            }

            try {
                $result = $retention->run($cabinet);
                $deletedCount += $result['deleted_count'];
            } catch (Throwable) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->components->error(
                'La rétention Google Drive a échoué par sécurité; aucune autre suppression n’a été tentée pour le compte concerné.',
            );

            return self::FAILURE;
        }

        $this->components->info(
            sprintf(
                'Rétention Google Drive terminée : %d sauvegarde(s) supprimée(s).',
                $deletedCount,
            ),
        );

        return self::SUCCESS;
    }

    private function recordCommandFailure(string $reasonCode): void
    {
        try {
            AuditLog::record('backup.drive_retention_failed', metadata: [
                'reason_code' => $reasonCode,
            ]);
            ApplicationEvent::record('BackupDriveRetentionFailed', 'warning', context: [
                'reason_code' => $reasonCode,
            ]);
        } catch (Throwable) {
            // The retention operation remains disabled even when diagnostics
            // storage is unavailable.
        }
    }
}
