<?php

namespace App\Console\Commands;

use App\Backups\BackupArchiveException;
use App\Backups\OfflineRestoreExecutor;
use App\Backups\PreparedRestore;
use App\Backups\RestoreLifecycleLease;
use App\Backups\RestoreLifecycleLock;
use App\Backups\RestoreRecoveryJournal;
use App\Backups\RestoreTargetSet;
use App\Backups\SupervisorOfflineRestoreGuard;
use App\Backups\VerifiedRestoreSafetyBackupProvider;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class NativeApplyOfflineRestore extends Command
{
    public const EXIT_REFUSED_NO_MUTATION = 10;

    public const EXIT_ROLLED_BACK = 20;

    public const EXIT_MANUAL_RECOVERY_REQUIRED = 30;

    protected $signature = 'medismart:restore:native-apply
                            {operation : Prepared restore operation UUID}';

    protected $description = 'Internal native-supervisor entry point for an offline restore';

    public function __construct()
    {
        parent::__construct();
        $this->setHidden(true);
    }

    public function handle(
        OfflineRestoreExecutor $executor,
        VerifiedRestoreSafetyBackupProvider $safetyBackups,
        RestoreLifecycleLock $lifecycleLock,
    ): int {
        $operationId = (string) $this->argument('operation');
        $applyAttempted = false;
        $lifecycleLease = null;

        if (PHP_SAPI !== 'cli' || getenv('MEDISMART_NATIVE_RESTORE') !== '1' || ! defined('STDIN')) {
            return $this->emit(
                'refused_no_mutation',
                'La restauration a été refusée avant toute modification des données actives.',
                self::EXIT_REFUSED_NO_MUTATION,
            );
        }

        try {
            $lifecycleLease = $lifecycleLock->tryAcquire();

            if (! $lifecycleLease instanceof RestoreLifecycleLease) {
                return $this->emit(
                    'refused_no_mutation',
                    'La restauration a été refusée avant toute modification des données actives.',
                    self::EXIT_REFUSED_NO_MUTATION,
                );
            }

            $guard = SupervisorOfflineRestoreGuard::fromStream(STDIN, $operationId);
            $restore = PreparedRestore::load($operationId);
            $targets = RestoreTargetSet::fromConfiguration();
            $applyAttempted = true;
            $executor->apply($restore, $targets, $guard, $safetyBackups);

            return $this->emit(
                'applied_pending_restart',
                'La restauration hors ligne a été appliquée. Les données de retour arrière sont conservées jusqu’à la validation du redémarrage.',
                self::SUCCESS,
            );
        } catch (Throwable) {
            if (! $applyAttempted) {
                return $this->emit(
                    'refused_no_mutation',
                    'La restauration a été refusée avant toute modification des données actives.',
                    self::EXIT_REFUSED_NO_MUTATION,
                );
            }

            return $this->emitFailureFromJournal($operationId);
        } finally {
            $lifecycleLease?->release();
        }
    }

    private function emitFailureFromJournal(string $operationId): int
    {
        try {
            $records = RestoreRecoveryJournal::open($operationId)->records();
            $last = end($records);
            $event = is_array($last) ? $last['event'] : null;
        } catch (BackupArchiveException) {
            $event = null;
        }

        if ($event === 'rollback_completed') {
            return $this->emit(
                'rolled_back',
                'La restauration a échoué, mais les données actives précédentes ont été rétablies. La copie de sécurité est conservée.',
                self::EXIT_ROLLED_BACK,
            );
        }

        if (in_array($event, ['ready_for_offline_apply', 'safety_backup_verified'], true)) {
            return $this->emit(
                'refused_no_mutation',
                'La restauration a été refusée avant toute modification des données actives.',
                self::EXIT_REFUSED_NO_MUTATION,
            );
        }

        return $this->emit(
            'manual_recovery_required',
            'La restauration est interrompue. Gardez l’application hors ligne et contactez l’assistance ; les données de retour arrière et les sauvegardes sont conservées.',
            self::EXIT_MANUAL_RECOVERY_REQUIRED,
        );
    }

    private function emit(string $status, string $message, int $exitCode): int
    {
        try {
            $line = json_encode([
                'protocol' => 'medismart-offline-restore-result',
                'version' => 1,
                'status' => $status,
                'message_fr' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $line = '{"protocol":"medismart-offline-restore-result","version":1,"status":"manual_recovery_required","message_fr":"Restauration interrompue ; assistance requise."}';
            $exitCode = self::EXIT_MANUAL_RECOVERY_REQUIRED;
        }

        $this->line($line);

        return $exitCode;
    }
}
