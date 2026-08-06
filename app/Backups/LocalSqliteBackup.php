<?php

namespace App\Backups;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalSqliteBackup
{
    /** @return array{path: string, filename: string} */
    public function create(): array
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('SQLite backups are only available when the SQLite database driver is active.');
        }

        $directory = storage_path('app/private/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('The local backup directory could not be created.');
        }

        $filename = 'medismart_backup_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(6)).'.sqlite3';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $pdo = DB::connection()->getPdo();
        $quotedPath = $pdo->quote($path);

        if ($pdo->exec("VACUUM INTO {$quotedPath}") === false) {
            throw new RuntimeException('The SQLite database could not be backed up.');
        }

        return ['path' => $path, 'filename' => $filename];
    }

    public function restore(UploadedFile $file): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('SQLite restore is only available when the SQLite database driver is active.');
        }

        $handle = fopen($file->getRealPath(), 'rb');
        $header = $handle === false ? '' : fread($handle, 16);
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\000") {
            throw new RuntimeException('The selected file is not a valid SQLite backup.');
        }

        $databasePath = (string) config('database.connections.sqlite.database');
        if ($databasePath === ':memory:' || $databasePath === '') {
            throw new RuntimeException('The active SQLite database has no restorable file.');
        }

        $this->create();
        DB::disconnect();

        if (! copy($file->getRealPath(), $databasePath)) {
            throw new RuntimeException('The active database could not be restored.');
        }

        DB::purge();
    }
}
