<?php

namespace App\Console\Commands;

use App\Backups\BackupArchiveException;
use App\Backups\PreparedRestore;
use App\Backups\RestoreRecoveryJournal;
use Illuminate\Console\Command;

final class InspectOfflineRestore extends Command
{
    private const NATIVE_RECOVERY_REQUIRED = 2;

    protected $signature = 'medismart:restore:inspect
                            {operation : Prepared restore operation UUID}';

    protected $description = 'Inspect a prepared restore and its non-secret recovery journal';

    public function handle(): int
    {
        $operationId = $this->argument('operation');

        try {
            $records = RestoreRecoveryJournal::open($operationId)->records();
            $last = end($records);
        } catch (BackupArchiveException $exception) {
            $this->error($exception->getMessage());

            return str_contains($exception->getMessage(), 'manual recovery')
                ? self::NATIVE_RECOVERY_REQUIRED
                : self::FAILURE;
        }

        if (! is_array($last)) {
            $this->error('The restore recovery journal has no usable state.');

            return self::FAILURE;
        }

        try {
            $restore = PreparedRestore::load($operationId);
        } catch (BackupArchiveException $exception) {
            $this->error($exception->getMessage());

            return $this->requiresNativeRecovery($last['event'])
                ? self::NATIVE_RECOVERY_REQUIRED
                : self::FAILURE;
        }

        $this->info('Prepared DrClickDz restore inspection');
        $this->line('Operation: '.$restore->operationId);
        $this->line('State: '.$last['event']);
        $this->line('Staged files: '.$restore->stagedFileCount);
        $this->line('Staged bytes: '.$restore->stagedBytes);
        $this->line('Backup created: '.($restore->manifest['created_at'] ?? 'unknown'));
        $this->warn('Online/web restore apply is disabled.');

        if ($this->requiresNativeRecovery($last['event'])) {
            $this->error('Native recovery attention is required. Keep Laravel and all writers stopped.');
            $this->warn('Original targets may remain at deterministic rollback paths; Tauri must inspect and recover them.');

            return self::NATIVE_RECOVERY_REQUIRED;
        }

        if ($last['event'] === 'rollback_completed') {
            $this->info('The failed apply was rolled back; active targets were restored.');
        } else {
            $this->warn('Apply or crash recovery requires exclusive ownership by the Tauri supervisor.');
        }

        return self::SUCCESS;
    }

    private function requiresNativeRecovery(string $event): bool
    {
        return in_array($event, [
            'apply_started',
            'target_swap_started',
            'target_backed_up',
            'target_installed',
            'apply_validation_passed',
            'rollback_started',
            'manual_recovery_required',
            'applied_pending_restart',
        ], true);
    }
}
