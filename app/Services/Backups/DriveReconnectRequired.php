<?php

namespace App\Services\Backups;

use InvalidArgumentException;

/**
 * Google refused the stored grant (revoked, expired after a password change,
 * or the account removed the app): retrying cannot help, the doctor has to
 * connect the account again. Permanent by design, hence InvalidArgumentException.
 */
final class DriveReconnectRequired extends InvalidArgumentException
{
    public const MESSAGE = 'La connexion Google Drive a expiré ou a été révoquée par Google : reconnectez le compte du cabinet.';

    public function __construct()
    {
        parent::__construct('The Google Drive grant was refused; the account must be connected again.');
    }
}
