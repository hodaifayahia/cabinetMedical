<?php

namespace App\Console\Commands;

use App\Backups\AbandonedRestorePreparationPruner;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Throwable;

final class PruneAbandonedRestorePreparations extends Command
{
    protected $signature = 'medismart:restore:prune-preparations';

    protected $description = 'Safely prune expired restore preparations that were never applied';

    public function handle(AbandonedRestorePreparationPruner $pruner): int
    {
        try {
            $report = $pruner->prune();
        } catch (Throwable) {
            AuditLog::record('restore.abandoned_preparations_prune_failed', metadata: [
                'reason_code' => 'configuration_or_managed_root_invalid',
                'destructive_actions_performed' => false,
            ]);
            $this->components->error('Restore preparation pruning stopped safely; no candidate was selected.');

            return self::FAILURE;
        }

        AuditLog::record(
            'restore.abandoned_preparations_pruned',
            metadata: $report->auditMetadata(),
        );

        if (! $report->lockAcquired) {
            $this->components->warn('Restore preparation pruning skipped because the lifecycle lock is busy.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Restore preparation pruning completed: %d expired pair(s) removed, %d protection condition(s) retained.',
            $report->prunedPairs,
            $report->protectedRecent
                + $report->protectedState
                + $report->protectedMismatch
                + $report->protectedUnsafe
                + $report->raceChanged,
        ));

        if ($report->failures > 0) {
            $this->components->error(sprintf(
                '%d candidate(s) could not be removed completely and require review.',
                $report->failures,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
