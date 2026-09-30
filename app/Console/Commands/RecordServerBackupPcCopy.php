<?php

namespace App\Console\Commands;

use App\Models\ServerBackupRun;
use Illuminate\Console\Command;

/**
 * Called over SSH by `scripts/server/pull-backup-to-pc.sh` once the operator's
 * Windows PC holds a verified copy, so the back office can show when the
 * last off-site copy on that PC was taken.
 */
class RecordServerBackupPcCopy extends Command
{
    protected $signature = 'drclick:server-backup:pc-copy {filename : Name of the backup file the PC now holds}';

    protected $description = 'Record that the operator’s PC holds a copy of a server backup';

    public function handle(): int
    {
        $filename = (string) $this->argument('filename');

        if (preg_match(RecordServerBackup::FILENAME_PATTERN, $filename) !== 1) {
            $this->components->error('Nom de sauvegarde invalide : '.$filename);

            return self::FAILURE;
        }

        $updated = ServerBackupRun::query()
            ->where('filename', $filename)
            ->update(['pc_copied_at' => now()]);

        if ($updated === 0) {
            $this->components->error('Sauvegarde inconnue : '.$filename);

            return self::FAILURE;
        }

        $this->components->info('Copie sur le PC enregistrée.');

        return self::SUCCESS;
    }
}
