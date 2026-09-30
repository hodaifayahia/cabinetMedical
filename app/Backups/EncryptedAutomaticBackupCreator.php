<?php

namespace App\Backups;

use App\Models\BackupRecord;
use SensitiveParameter;

interface EncryptedAutomaticBackupCreator
{
    /**
     * Create one completed, verified v2 (.msbackup, encrypted) archive in the
     * managed backup directory.
     */
    public function create(#[SensitiveParameter] string $passphrase): BackupRecord;
}
