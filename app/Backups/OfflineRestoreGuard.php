<?php

namespace App\Backups;

interface OfflineRestoreGuard
{
    /**
     * Must prove that the Tauri supervisor owns the restore lifecycle and that
     * no Laravel server, queue worker, or document writer can be active.
     */
    public function assertExclusiveProcessOwnership(): void;

    public function assertStillExclusive(): void;
}
