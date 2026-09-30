<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use FilesystemIterator;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SensitiveParameter;
use SplFileInfo;
use SQLite3;
use Throwable;

/**
 * Starts a brand-new desktop from a clinic backup (.msbackup, plain or
 * encrypted), typically on a new or reinstalled PC.
 *
 * Only an installation nobody has set up yet may be imported into: no user
 * and no cabinet. There is nothing to lose there, which is what allows a
 * simpler path than the supervised offline restore of a running clinic.
 *
 * The archive goes through the same authentication, checksum and SQLite
 * validation as any restore. The live database file stays open in the web
 * server, the queue worker and the scheduler, so it is not swapped: SQLite's
 * online backup API copies the validated database into it. The empty
 * database is kept aside first and copied back if anything fails.
 *
 * The backup may come from another PC, whose APP_KEY sealed its secrets
 * (connection tokens, two-factor secrets, encrypted settings). This PC keeps
 * its own identity, and every secret it cannot read is dropped: the doctor
 * reconnects Google Drive and the online service once.
 */
final class FirstRunBackupImporter
{
    public const ENCRYPTED_MAGIC = "MEDISMART-MSBAK\x02";

    private const ZIP_MAGIC = "PK\x03\x04";

    private const LOCK = 'medismart:first-run-import';

    /** Also held by every local backup: none may start during the import. */
    private const BACKUP_LOCK = 'medismart:scheduled-backup';

    /** Rows sealed with the source APP_KEY: dropped (delete) or blanked (null). */
    private const SEALED_COLUMNS = [
        ['application_settings', ['encrypted_value'], 'delete'],
        ['drive_backup_connections', ['access_token', 'refresh_token'], 'delete'],
        ['cloud_connections', ['encrypted_access_token', 'encrypted_refresh_token'], 'delete'],
        ['server_drive_connections', ['access_token', 'refresh_token'], 'delete'],
        ['tunnel_settings', ['encrypted_tunnel_token'], 'null'],
        ['hosted_license_grants', ['code_encrypted'], 'null'],
        ['users', ['two_factor_secret', 'two_factor_recovery_codes'], 'null'],
    ];

    /** State that only made sense on the source PC, or in the empty database. */
    private const TRANSIENT_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'google_drive_oauth_attempts',
    ];

    public function __construct(
        private readonly EncryptedMsBackupArchive $encryptedArchive,
        private readonly StagedMsBackupExtractor $extractor,
        private readonly StagedSqliteValidator $databaseValidator,
        private readonly ApplicationSettingRegistry $settingsRegistry,
    ) {}

    /** Only a supervised desktop nobody has set up yet may start from a backup. */
    public function available(): bool
    {
        try {
            return (bool) config('medismart.runtime.desktop_supervised', false)
                && DB::connection()->getDriverName() === 'sqlite'
                && class_exists(SQLite3::class)
                && Schema::hasTable('users')
                && Schema::hasTable('cabinets')
                && ! DB::table('users')->exists()
                && ! DB::table('cabinets')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function isEncrypted(string $path): bool
    {
        return $this->header($path, strlen(self::ENCRYPTED_MAGIC)) === self::ENCRYPTED_MAGIC;
    }

    /**
     * @return array{cabinet: string|null, patients: int, users: int, created_at: string|null, application_version: string|null}
     *
     * @throws FirstRunImportException
     */
    public function import(string $uploadedPath, #[SensitiveParameter] ?string $passphrase): array
    {
        // The file store, not the database one: the database is replaced.
        $store = Cache::store('file')->getStore();

        if (! $store instanceof LockProvider) {
            throw new FirstRunImportException(FirstRunImportException::UNAVAILABLE);
        }

        $lock = $store->lock(self::LOCK, 3600);

        if (! $lock->get()) {
            throw new FirstRunImportException(FirstRunImportException::BUSY);
        }

        $backupLock = null;
        $workspace = null;

        try {
            if (! $this->available()) {
                throw new FirstRunImportException(FirstRunImportException::UNAVAILABLE);
            }

            $backupLock = Cache::lock(self::BACKUP_LOCK, 3600);

            if (! $backupLock->get()) {
                $backupLock = null;

                throw new FirstRunImportException(FirstRunImportException::BUSY);
            }

            $workspace = $this->createWorkspace();
            $archive = $this->plainArchive($uploadedPath, $passphrase, $workspace);
            $staging = $workspace.DIRECTORY_SEPARATOR.'staged';
            $this->ensureDirectory($staging);

            try {
                $extracted = $this->extractor->extract($archive, $staging);
            } catch (Throwable $exception) {
                throw new FirstRunImportException(FirstRunImportException::INVALID_ARCHIVE, $exception);
            }

            $stagedDatabase = $staging.DIRECTORY_SEPARATOR.'database.sqlite3';

            try {
                $this->databaseValidator->validate($stagedDatabase, $extracted['manifest'], allowOlderSchema: true);
            } catch (BackupArchiveException $exception) {
                throw new FirstRunImportException(
                    str_contains($exception->getMessage(), 'newer build')
                        ? FirstRunImportException::NEWER_VERSION
                        : FirstRunImportException::INVALID_ARCHIVE,
                    $exception,
                );
            }

            $summary = $this->summarize($stagedDatabase, $extracted['manifest']);

            // Checked again right before anything is written: nobody may have
            // registered on this PC while the archive was being verified.
            if (! $this->available()) {
                throw new FirstRunImportException(FirstRunImportException::UNAVAILABLE);
            }

            try {
                $this->installManagedFiles($staging);
                $this->replaceDatabase($stagedDatabase, $workspace);
            } catch (FirstRunImportException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new FirstRunImportException(FirstRunImportException::APPLY_FAILED, $exception);
            }

            $this->recordHistory($summary, $extracted['archive_sha256']);

            return $summary;
        } finally {
            if ($workspace !== null) {
                $this->removeDirectory($workspace);
            }

            // The database lock row went with the empty database; releasing
            // it is only housekeeping.
            try {
                $backupLock?->release();
            } catch (Throwable) {
            }

            $lock->release();
        }
    }

    /** The authenticated, plain .msbackup to extract, inside the workspace. */
    private function plainArchive(string $uploadedPath, #[SensitiveParameter] ?string $passphrase, string $workspace): string
    {
        $plain = $workspace.DIRECTORY_SEPARATOR.'backup.msbackup';

        if ($this->isEncrypted($uploadedPath)) {
            if ($passphrase === null || $passphrase === '') {
                throw new FirstRunImportException(FirstRunImportException::PASSPHRASE_REQUIRED);
            }

            try {
                $this->encryptedArchive->decrypt($uploadedPath, $plain, $passphrase, requireSourceExtension: false);
            } catch (Throwable $exception) {
                throw new FirstRunImportException(FirstRunImportException::DECRYPTION_FAILED, $exception);
            }

            return $plain;
        }

        if ($this->header($uploadedPath, strlen(self::ZIP_MAGIC)) !== self::ZIP_MAGIC
            || ! @copy($uploadedPath, $plain)) {
            throw new FirstRunImportException(FirstRunImportException::INVALID_ARCHIVE);
        }

        return $plain;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{cabinet: string|null, patients: int, users: int, created_at: string|null, application_version: string|null}
     */
    private function summarize(string $database, array $manifest): array
    {
        $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');
        $scalar = static function (string $sql) use ($pdo): mixed {
            $statement = $pdo->query($sql);

            return $statement === false ? null : $statement->fetchColumn();
        };
        $count = static fn (string $table): int => (int) $scalar('SELECT count(*) FROM "'.$table.'"');
        $cabinet = null;

        try {
            $name = $scalar('SELECT name FROM cabinets ORDER BY id LIMIT 1');
            $cabinet = is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            // An older backup without the cabinets table still restores.
        }

        $summary = [
            'cabinet' => $cabinet,
            'patients' => $count('patients'),
            'users' => $count('users'),
            'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : null,
            'application_version' => is_string($manifest['application_version'] ?? null)
                ? $manifest['application_version']
                : null,
        ];
        $pdo = null;

        return $summary;
    }

    /**
     * Documents, scans, medical models and the cabinet's logos. Written before
     * the database, so a failure there leaves the PC as empty as it was.
     */
    private function installManagedFiles(string $staging): void
    {
        $stagedRoots = [
            'clinical_documents' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'clinical-documents',
            'patient_documents' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'patient-documents',
            'medical_models' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical-models',
            'cabinet' => $staging.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'cabinet',
        ];

        foreach (RestoreTargetSet::fromConfiguration()->managedRoots as $key => $activeRoot) {
            $stagedRoot = $stagedRoots[$key];

            if (is_dir($stagedRoot) && ! is_link($stagedRoot)) {
                $this->copyDirectory($stagedRoot, $activeRoot);
            }
        }
    }

    private function replaceDatabase(string $stagedDatabase, string $workspace): void
    {
        $live = DB::connection()->getDatabaseName();

        if (! is_string($live) || $live === '' || $live === ':memory:' || ! is_file($live)) {
            throw new FirstRunImportException(FirstRunImportException::UNAVAILABLE);
        }

        $machineSettings = $this->machineBoundSettings();
        $emptyCopy = $workspace.DIRECTORY_SEPARATOR.'empty-installation.sqlite';
        $this->copyDatabase($live, $emptyCopy);
        DB::disconnect();

        try {
            $this->copyDatabase($stagedDatabase, $live);
            DB::reconnect();
            $this->bringSchemaForward();
            $this->adoptOnThisMachine($machineSettings);
        } catch (Throwable $exception) {
            DB::disconnect();

            try {
                $this->copyDatabase($emptyCopy, $live);
            } catch (Throwable) {
                // Reported through the original failure below.
            }

            DB::reconnect();

            throw $exception;
        }
    }

    private function copyDatabase(string $from, string $to): void
    {
        $source = new SQLite3($from, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($to, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

        try {
            $source->busyTimeout(30000);
            $destination->busyTimeout(30000);

            if (! $source->backup($destination)) {
                throw new BackupArchiveException('The SQLite online backup could not copy the database.');
            }
        } finally {
            $source->close();
            $destination->close();
        }
    }

    /** A backup from an older build gets this build's migrations. */
    private function bringSchemaForward(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new BackupArchiveException('The restored database could not be migrated.');
        }
    }

    /**
     * This PC's own settings rows (installation identity, machine seed,
     * trusted time, native desktop preferences…), exactly as stored.
     *
     * @return list<array<string, mixed>>
     */
    private function machineBoundSettings(): array
    {
        $rows = [];

        foreach (DB::table('application_settings')->whereIn('key', $this->machineBoundKeys())->get() as $row) {
            $values = (array) $row;
            // The restored table owns its ids.
            unset($values['id']);
            $rows[] = $values;
        }

        return $rows;
    }

    /** @return list<string> */
    private function machineBoundKeys(): array
    {
        $keys = [];

        foreach ($this->settingsRegistry->all() as $definition) {
            if ($definition->backupPolicy !== 'portable') {
                $keys[] = $definition->key;
            }
        }

        return $keys;
    }

    /** @param list<array<string, mixed>> $machineSettings */
    private function adoptOnThisMachine(array $machineSettings): void
    {
        DB::transaction(function () use ($machineSettings): void {
            DB::table('application_settings')->whereIn('key', $this->machineBoundKeys())->delete();

            foreach ($machineSettings as $row) {
                DB::table('application_settings')->insert($row);
            }

            $this->dropSealedSecrets();

            foreach (self::TRANSIENT_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // The source PC's archives are not on this one: listing them as
            // restore points would skip this PC's own first backups.
            if (Schema::hasTable('backup_records')) {
                DB::table('backup_records')
                    ->orderBy('id')
                    ->get(['id', 'local_path'])
                    ->each(function (object $record): void {
                        if (! is_string($record->local_path) || ! is_file($record->local_path)) {
                            DB::table('backup_records')->where('id', $record->id)->delete();
                        }
                    });
            }
        });

        try {
            Cache::flush();
        } catch (Throwable) {
            // The database cache table was already emptied above.
        }

        // Long-running workers still hold state from the empty database.
        Artisan::call('queue:restart');
    }

    private function dropSealedSecrets(): void
    {
        foreach (self::SEALED_COLUMNS as [$table, $columns, $action]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($columns === []) {
                continue;
            }

            $rows = DB::table($table)
                ->select(['rowid as __rowid', ...$columns])
                ->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->get();

            foreach ($rows as $row) {
                $readable = true;

                foreach ($columns as $column) {
                    if ($row->{$column} !== null && ! $this->decryptable((string) $row->{$column})) {
                        $readable = false;

                        break;
                    }
                }

                if ($readable) {
                    continue;
                }

                $query = DB::table($table)->whereRaw('rowid = ?', [$row->__rowid]);

                if ($action === 'delete') {
                    $query->delete();

                    continue;
                }

                $blank = array_fill_keys($columns, null);

                if ($table === 'users' && Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                    $blank['two_factor_confirmed_at'] = null;
                }

                $query->update($blank);
            }
        }
    }

    private function decryptable(string $payload): bool
    {
        try {
            Crypt::decryptString($payload);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{cabinet: string|null, patients: int, users: int, created_at: string|null, application_version: string|null} $summary */
    private function recordHistory(array $summary, string $archiveSha256): void
    {
        try {
            AuditLog::record('backup.first_run_imported', metadata: [
                'patients' => $summary['patients'],
                'users' => $summary['users'],
                'backup_created_at' => $summary['created_at'],
                'backup_application_version' => $summary['application_version'],
                'archive_sha256' => $archiveSha256,
            ]);
            ApplicationEvent::record('FirstRunBackupImported', context: [
                'patients' => $summary['patients'],
                'archive_sha256' => $archiveSha256,
            ]);
        } catch (Throwable) {
            // The clinic's data is in place; missing history must not undo it.
        }
    }

    private function createWorkspace(): string
    {
        $root = storage_path('app/private/restore-work');
        $this->ensureDirectory($root);
        $workspace = $root.DIRECTORY_SEPARATOR.'first-run-'.Str::uuid();

        if (! @mkdir($workspace, 0700)) {
            throw new FirstRunImportException(FirstRunImportException::APPLY_FAILED);
        }

        return $workspace;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new FirstRunImportException(FirstRunImportException::APPLY_FAILED);
        }
    }

    private function copyDirectory(string $from, string $to): void
    {
        $this->ensureDirectory($to);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }

            $target = $to.DIRECTORY_SEPARATOR.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                $this->ensureDirectory($target);
            } elseif (! @copy($item->getPathname(), $target)) {
                throw new BackupArchiveException('A restored document could not be written.');
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        try {
            if (! is_dir($directory) || is_link($directory)) {
                return;
            }

            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            /** @var SplFileInfo $item */
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname())
                    : @unlink($item->getPathname());
            }

            @rmdir($directory);
        } catch (Throwable) {
            // Left for the abandoned-restore pruner.
        }
    }

    /** @param positive-int $length */
    private function header(string $path, int $length): string
    {
        $handle = @fopen($path, 'rb');

        if (! is_resource($handle)) {
            return '';
        }

        try {
            $header = fread($handle, $length);
        } finally {
            fclose($handle);
        }

        return is_string($header) ? $header : '';
    }
}
