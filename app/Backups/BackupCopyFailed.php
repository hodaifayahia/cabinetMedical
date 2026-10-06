<?php

namespace App\Backups;

use RuntimeException;

/** A copy to the doctor's chosen folder failed; the message is French and safe to show. */
final class BackupCopyFailed extends RuntimeException {}
