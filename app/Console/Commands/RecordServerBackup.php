<?php

namespace App\Console\Commands;

use App\Enums\ServerBackupDriveStatus;
use App\Models\ServerBackupRun;
use App\Services\Backups\ServerBackupDrive;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Record a nightly server backup written by `scripts/server/nightly-backup.sh`
 * and send it to the platform's Google Drive when one is connected.
 *
 * Fails (exit 1) when the Drive copy did not happen, so the cron log shows
 * it; the encrypted file stays on the server either way.
 */
class RecordServerBackup extends Command
{
    public const FILENAME_PATTERN = '/\Adrclick-server-\d{8}-\d{6}\.(sqlite|sql)\.gz\.enc\z/';

    protected $signature = 'drclick:server-backup:record {path : Absolute path of the encrypted backup file}';

    protected $description = 'Record a nightly server backup and send it to Google Drive';

    public function handle(ServerBackupDrive $drive): int
    {
        $path = (string) $this->argument('path');
        $filename = basename($path);

        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1 || ! is_file($path)) {
            $this->components->error('Fichier de sauvegarde introuvable ou mal nommé : '.$path);

            return self::FAILURE;
        }

        $path = (string) realpath($path);
        $run = ServerBackupRun::query()->updateOrCreate(['filename' => $filename], [
            'path' => $path,
            'size_bytes' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
            'database_driver' => (string) config('database.connections.'.config('database.default').'.driver'),
            'drive_status' => ServerBackupDriveStatus::PENDING,
            'drive_error' => null,
        ]);

        if (! $drive->isConnected()) {
            $run->update(['drive_status' => ServerBackupDriveStatus::SKIPPED]);
            $this->components->warn('Sauvegarde enregistrée. Aucun Google Drive connecté : copie Drive ignorée.');

            return self::SUCCESS;
        }

        try {
            $drive->upload($run, $path);
        } catch (RuntimeException $exception) {
            $this->components->error('Copie Google Drive échouée : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sauvegarde enregistrée et envoyée sur Google Drive.');

        return self::SUCCESS;
    }
}
