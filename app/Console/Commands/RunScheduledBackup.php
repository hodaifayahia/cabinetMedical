<?php

namespace App\Console\Commands;

use App\Backups\AutomaticBackupCreator;
use App\Backups\LocalBackupRetentionManager;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunScheduledBackup extends Command
{
    protected $signature = 'medismart:backup:scheduled {--force : Crée une sauvegarde même si la planification est désactivée}';

    protected $description = 'Crée au plus une sauvegarde locale vérifiée par journée planifiée';

    public function handle(
        ApplicationSettingService $settings,
        AutomaticBackupCreator $creator,
        LocalBackupRetentionManager $retention,
    ): int {
        $forced = (bool) $this->option('force');

        if (! $forced
            && (! (bool) config('medismart.runtime.desktop_supervised', false)
                || config('medismart.runtime.scheduler_status') !== 'active')) {
            $this->components->info('Sauvegarde ignorée : scheduler desktop non supervisé.');

            return self::SUCCESS;
        }

        if (! $forced && ! (bool) $settings->get(Setting::BACKUP_AUTOMATIC_ENABLED)) {
            $this->components->info('Sauvegarde automatique désactivée.');

            return self::SUCCESS;
        }

        $scheduledAt = $this->scheduledAt((string) $settings->get(Setting::BACKUP_SCHEDULE_TIME));

        if (! $forced && CarbonImmutable::now()->isBefore($scheduledAt)) {
            $this->components->info('Sauvegarde ignorée : heure planifiée non atteinte.');

            return self::SUCCESS;
        }

        if (! $forced && BackupRecord::query()
            ->where('status', 'completed')
            ->where('started_at', '>=', $scheduledAt)
            ->exists()) {
            $this->components->info('Sauvegarde ignorée : une archive vérifiée existe déjà pour cette échéance.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('medismart:scheduled-backup', 3600);

        if (! $lock->get()) {
            $this->components->info('Sauvegarde ignorée : une création est déjà en cours.');

            return self::SUCCESS;
        }

        try {
            $record = $creator->create();
            AuditLog::record('backup.scheduled_completed', $record, [
                'scheduled_for' => $scheduledAt->toIso8601String(),
                'size' => $record->size,
                'sha256' => $record->sha256,
            ]);
            ApplicationEvent::record('ScheduledBackupCompleted', context: [
                'backup_record_id' => $record->getKey(),
                'scheduled_for' => $scheduledAt->toIso8601String(),
            ]);
            $this->applyConfiguredRetention($retention, $record);
        } catch (Throwable) {
            ApplicationEvent::record('ScheduledBackupFailed', 'error', context: [
                'scheduled_for' => $scheduledAt->toIso8601String(),
                'error' => 'scheduled_backup_failed',
            ]);
            $this->components->error('La sauvegarde planifiée a échoué; aucune réussite n’a été enregistrée.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->components->info('Sauvegarde planifiée créée et vérifiée.');

        return self::SUCCESS;
    }

    private function scheduledAt(string $time): CarbonImmutable
    {
        if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) !== 1) {
            $time = '02:00';
        }

        return CarbonImmutable::today()->setTime(
            (int) substr($time, 0, 2),
            (int) substr($time, 3, 2),
        );
    }

    private function applyConfiguredRetention(
        LocalBackupRetentionManager $retention,
        BackupRecord $newestBackup,
    ): void {
        try {
            $preview = $retention->preview();
            $result = $retention->apply(
                confirmationToken: $retention->issueConfirmation($preview),
                internalConfirmation: true,
            );
            ApplicationEvent::record('BackupRetentionCompleted', context: [
                'trigger_backup_record_id' => $newestBackup->getKey(),
                'plan_sha256' => $result['plan_sha256'] ?? null,
                'deleted_count' => $result['deleted_count'] ?? 0,
            ]);
        } catch (Throwable) {
            ApplicationEvent::record('BackupRetentionFailed', 'warning', context: [
                'trigger_backup_record_id' => $newestBackup->getKey(),
                'error' => 'local_retention_failed_closed',
            ]);
            $this->components->warn(
                'La sauvegarde est valide, mais la rétention locale a été ignorée par sécurité.',
            );
        }
    }
}
