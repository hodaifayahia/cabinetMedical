<?php

namespace App\Backups;

final readonly class RestoreSafetyBackupReceipt
{
    public function __construct(
        public string $path,
        public string $sha256,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new BackupArchiveException('The restore safety-backup receipt is invalid.');
        }
    }
}
