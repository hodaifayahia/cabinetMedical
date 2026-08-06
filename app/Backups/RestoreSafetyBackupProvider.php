<?php

namespace App\Backups;

interface RestoreSafetyBackupProvider
{
    public function createSafetyBackup(PreparedRestore $restore): RestoreSafetyBackupReceipt;
}
