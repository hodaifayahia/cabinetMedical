<?php

namespace App\Backups;

use App\Models\BackupRecord;

interface AutomaticBackupCreator
{
    public function create(): BackupRecord;
}
