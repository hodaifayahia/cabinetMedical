<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class ApplyOfflineRestore extends Command
{
    protected $signature = 'medismart:restore:apply
                            {operation : Prepared restore operation UUID}';

    protected $description = 'Fail-closed gate for the not-yet-supervised offline restore apply step';

    public function handle(): int
    {
        $this->error('Restore apply is disabled until the Tauri supervisor can prove exclusive process ownership.');
        $this->warn('No active database or managed document was changed.');

        return self::FAILURE;
    }
}
