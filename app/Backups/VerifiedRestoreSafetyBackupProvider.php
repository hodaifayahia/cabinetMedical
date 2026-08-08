<?php

namespace App\Backups;

use Illuminate\Support\Str;

final class VerifiedRestoreSafetyBackupProvider implements RestoreSafetyBackupProvider
{
    public function __construct(
        private readonly MsBackupArchiveCreator $creator,
        private readonly ?string $destinationDirectory = null,
    ) {}

    public function createSafetyBackup(PreparedRestore $restore): RestoreSafetyBackupReceipt
    {
        $backupId = (string) Str::uuid();
        $filename = 'Drclick-Pre-Restore-Safety-'
            .now()->format('Y-m-d-His').'-'.$restore->operationId.'-'.$backupId.'.msbackup';
        $managedRoot = (string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        );
        $created = $this->creator->create(
            $this->destinationDirectory
                ?? rtrim($managedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pre-restore-safety',
            $filename,
            $backupId,
        );

        if (($created['manifest']['installation_id'] ?? null)
            !== ($restore->manifest['installation_id'] ?? null)) {
            throw new BackupArchiveException('The pre-restore safety backup installation identity does not match.');
        }

        return new RestoreSafetyBackupReceipt($created['path'], $created['sha256']);
    }
}
