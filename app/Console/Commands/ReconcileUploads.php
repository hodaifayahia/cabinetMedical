<?php

namespace App\Console\Commands;

use App\Services\UploadReconciliationService;
use Illuminate\Console\Command;

final class ReconcileUploads extends Command
{
    protected $signature = 'medismart:uploads:reconcile';

    protected $description = 'Expire stale upload credentials and safely reconcile quarantine storage';

    public function handle(UploadReconciliationService $reconciliation): int
    {
        $report = $reconciliation->reconcile();

        $this->components->info(sprintf(
            'Upload reconciliation completed: %d sessions expired, %d rejected files and %d orphan files removed.',
            $report['sessions_expired'],
            $report['rejected_files_deleted'],
            $report['orphan_files_deleted'],
        ));

        if ($report['attention_required'] > 0) {
            $this->components->warn(sprintf(
                '%d item(s) require review; no pending or accepted medical file was deleted.',
                $report['attention_required'],
            ));
        }

        return self::SUCCESS;
    }
}
