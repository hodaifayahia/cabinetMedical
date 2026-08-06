<?php

namespace App\Services\Backups;

use RuntimeException;

final class DriveUploadCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Google Drive backup upload was cancelled.');
    }
}
