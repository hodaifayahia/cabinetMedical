<?php

namespace App\Backups;

use App\Models\BackupRecord;
use App\Services\BackupService;
use RuntimeException;

final class LocalAutomaticBackupCreator implements AutomaticBackupCreator
{
    public function __construct(private readonly BackupService $backups) {}

    public function create(): BackupRecord
    {
        $archive = $this->backups->createArchive();
        $record = $archive['record'];

        if ($record->status !== 'completed'
            || $record->completed_at === null) {
            throw new RuntimeException('The scheduled backup was not completed and verified.');
        }

        return $record;
    }
}
