<?php

namespace App\Services;

use RuntimeException;

final class GoogleDriveOAuthException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('The Google Drive authorization attempt could not be completed.');
    }
}
