<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SensitiveParameter;
use SplFileInfo;
use SQLite3;
use Throwable;
use ZipArchive;

/**
 * Restores a clinic backup (.msbackup, plain or encrypted) over the running
 * desktop, from Configuration › Sauvegardes, in two steps:
 *
 * 1. prepare(): the archive is authenticated (passphrase, checksums, SQLite
 *    validation) and staged in a private workspace. Nothing active changes;
 *    the doctor sees what the backup holds.
 * 2. apply(): after the doctor confirmed, a verified safety backup of the
 *    current data is written first, then the documents and the database are
 *    replaced. Any failure puts the previous documents and database back.
 *
 * The live database file stays open in the queue worker and the scheduler,
 * so it is not swapped on disk: SQLite's online backup API copies the
 * validated database into it, exactly as the first-run import does. This
 * PC's machine-bound settings and its own backup archives are carried over,
 * secrets sealed with another PC's key are dropped, and every session ends:
 * everybody signs in again on the restored data.
 */
final class InAppBackupRestorer
{
    public const SAFETY_DIRECTORY = 'pre-restore-safety';

    private const ENCRYPTED_MAGIC = "MEDISMART-MSBAK\x02";

    private const ZIP_MAGIC = "PK\x03\x04";

    private const WORKSPACE_PREFIX = 'in-app-';

    private const LOCK = 'medismart:in-app-restore';

    /** Also held by every local backup: none may start during a restore. */
    private const BACKUP_LOCK = 'medismart:scheduled-backup';

    private const PREPARED_TTL_SECONDS = 2 * 3600;

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

    /** State that only made sense in the replaced database. */
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
        private readonly MsBackupArchiveCreator $archiveCreator,
        private readonly ApplicationSettingRegistry $settingsRegistry,
    ) {}

    /** Only the supervised desktop, whose database is a local SQLite file, may restore in place. */
    public function available(): bool
    {
        try {
            $database = DB::connection()->getDatabaseName();

            return (bool) config('medismart.runtime.desktop_supervised', false)
                && DB::connection()->getDriverName() === 'sqlite'
                && class_exists(SQLite3::class)
                && class_exists(ZipArchive::class)
                && is_string($database)
                && $database !== ''
                && $database !== ':memory:'
                && is_file($database);
        } catch (Throwable) {
            return false;
        }
    }

    public function isEncrypted(string $path): bool
    {
        return $this->header($path, strlen(self::ENCRYPTED_MAGIC)) === self::ENCRYPTED_MAGIC;
    }

    /**
     * Authenticate and stage an archive. Nothing active is touched.
     *
     * @return array{operation_id: string, summary: array<string, mixed>}
     *
     * @throws InAppRestoreException
     */
    public function prepare(string $archivePath, #[SensitiveParameter] ?string $passphrase): array
    {
        if (! $this->available()) {
            throw new InAppRestoreException(InAppRestoreException::UNAVAILABLE);
        }

        $this->pruneStaleWorkspaces();
        $lock = $this->acquireRestoreLock();
        $operationId = (string) Str::uuid();
        $workspace = null;

        try {
            $workspace = $this->createWorkspace($operationId);
            $archive = $this->plainArchive($archivePath, $passphrase, $workspace);
            $staging = $workspace.DIRECTORY_SEPARATOR.'staged';
            $this->ensureDirectory($staging);

            try {
                $extracted = $this->extractor->extract($archive, $staging);
            } catch (Throwable $exception) {
                throw new InAppRestoreException(InAppRestoreException::INVALID_ARCHIVE, $exception);
            }

            $stagedDatabase = $staging.DIRECTORY_SEPARATOR.'database.sqlite3';
            $this->validateStagedDatabase($stagedDatabase, $extracted['manifest']);
            $summary = [
                ...$this->summarize($stagedDatabase, $extracted['manifest']),
                'file_count' => max(0, $extracted['file_count'] - 1),
                'encrypted' => $this->isEncrypted($archivePath),
            ];

            // Only the staged tree is kept; the decrypted archive goes now.
            if ($archive !== $archivePath) {
                @unlink($archive);
            }

            $this->writePrepared($workspace, [
                'operation_id' => $operationId,
                'prepared_at' => time(),
                'archive_sha256' => $extracted['archive_sha256'],
                'manifest' => $extracted['manifest'],
                'summary' => $summary,
            ]);

            return ['operation_id' => $operationId, 'summary' => $summary];
        } catch (Throwable $exception) {
            if ($workspace !== null) {
                $this->removeDirectory($workspace);
            }

            throw $exception instanceof InAppRestoreException
                ? $exception
                : new InAppRestoreException(InAppRestoreException::INVALID_ARCHIVE, $exception);
        } finally {
            $lock->release();
        }
    }

    /** Forget a prepared archive the doctor did not restore. */
    public function discard(string $operationId): void
    {
        $workspace = $this->workspacePath($operationId);

        if ($workspace !== null) {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Replace the active data with a prepared archive.
     *
     * @return array{summary: array<string, mixed>, safety_backup: string}
     *
     * @throws InAppRestoreException
     */
    public function apply(string $operationId, ?User $actor = null): array
    {
        if (! $this->available()) {
            throw new InAppRestoreException(InAppRestoreException::UNAVAILABLE);
        }

        $workspace = $this->workspacePath($operationId);

        if ($workspace === null) {
            throw new InAppRestoreException(InAppRestoreException::EXPIRED);
        }

        $lock = $this->acquireRestoreLock();
        $backupLock = null;

        try {
            $prepared = $this->readPrepared($workspace, $operationId);
            $backupLock = Cache::lock(self::BACKUP_LOCK, 3600);

            if (! $backupLock->get()) {
                $backupLock = null;

                throw new InAppRestoreException(InAppRestoreException::BUSY);
            }

            $staging = $workspace.DIRECTORY_SEPARATOR.'staged';
            $stagedDatabase = $staging.DIRECTORY_SEPARATOR.'database.sqlite3';
            // Checked again: the staged copy must still be the verified one.
            $this->validateStagedDatabase($stagedDatabase, $prepared['manifest']);

            $live = (string) DB::connection()->getDatabaseName();
            $safety = $this->createSafetyBackup($operationId);
            $carriedOver = $this->localBackupRecords();
            $machineSettings = $this->machineBoundSettings();
            $databaseRollback = $workspace.DIRECTORY_SEPARATOR.'database-before-restore.sqlite';
            $this->copyDatabase($live, $databaseRollback);
            $moved = [];
            $databaseReplaced = false;

            try {
                $moved = $this->installManagedFiles($staging, $operationId);
                $databaseReplaced = true;
                $this->replaceDatabase($stagedDatabase, $live);
                $this->adoptOnThisMachine($machineSettings, [...$carriedOver, $safety['row']]);
            } catch (Throwable $exception) {
                if ($databaseReplaced) {
                    $this->restoreDatabase($databaseRollback, $live);
                }

                $this->rollbackManagedFiles($moved);

                throw new InAppRestoreException(
                    $exception instanceof InAppRestoreException ? $exception->reason : InAppRestoreException::APPLY_FAILED,
                    $exception,
                );
            }

            $this->discardRollbacks($moved);
            $this->recordHistory($prepared, $safety['filename'], $actor);
            $this->resetRuntimeState();

            return ['summary' => $prepared['summary'], 'safety_backup' => $safety['filename']];
        } finally {
            $this->removeDirectory($workspace);

            // The database lock row went with the replaced database; releasing
            // it is only housekeeping.
            try {
                $backupLock?->release();
            } catch (Throwable) {
            }

            $lock->release();
        }
    }

    private function acquireRestoreLock(): Lock
    {
        // The file store, not the database one: the database is replaced.
        $store = Cache::store('file')->getStore();

        if (! $store instanceof LockProvider) {
            throw new InAppRestoreException(InAppRestoreException::UNAVAILABLE);
        }

        $lock = $store->lock(self::LOCK, 3600);

        if (! $lock->get()) {
            throw new InAppRestoreException(InAppRestoreException::BUSY);
        }

        return $lock;
    }

    /** The authenticated, plain .msbackup to extract. */
    private function plainArchive(string $path, #[SensitiveParameter] ?string $passphrase, string $workspace): string
    {
        if ($this->isEncrypted($path)) {
            if ($passphrase === null || $passphrase === '') {
                throw new InAppRestoreException(InAppRestoreException::PASSPHRASE_REQUIRED);
            }

            $plain = $workspace.DIRECTORY_SEPARATOR.'backup.msbackup';

            try {
                $this->encryptedArchive->decrypt($path, $plain, $passphrase, requireSourceExtension: false);
            } catch (Throwable $exception) {
                throw new InAppRestoreException(InAppRestoreException::DECRYPTION_FAILED, $exception);
            }

            return $plain;
        }

        if ($this->header($path, strlen(self::ZIP_MAGIC)) !== self::ZIP_MAGIC) {
            throw new InAppRestoreException(InAppRestoreException::INVALID_ARCHIVE);
        }

        return $path;
    }

    /** @param array<string, mixed> $manifest */
    private function validateStagedDatabase(string $database, array $manifest): void
    {
        try {
            $this->databaseValidator->validate($database, $manifest, allowOlderSchema: true);
        } catch (BackupArchiveException $exception) {
            throw new InAppRestoreException(
                str_contains($exception->getMessage(), 'newer build')
                    ? InAppRestoreException::NEWER_VERSION
                    : InAppRestoreException::INVALID_ARCHIVE,
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{cabinet: string|null, patients: int, users: int, consultations: int|null, created_at: string|null, application_version: string|null}
     */
    private function summarize(string $database, array $manifest): array
    {
        $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');
        $scalar = static function (string $sql) use ($pdo): mixed {
            $statement = $pdo->query($sql);

            return $statement === false ? null : $statement->fetchColumn();
        };
        $count = static function (string $table) use ($scalar): ?int {
            try {
                return (int) $scalar('SELECT count(*) FROM "'.$table.'"');
            } catch (Throwable) {
                return null;
            }
        };
        $cabinet = null;

        try {
            $name = $scalar('SELECT name FROM cabinets ORDER BY id LIMIT 1');
            $cabinet = is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            // An older backup without the cabinets table still restores.
        }

        $summary = [
            'cabinet' => $cabinet,
            'patients' => (int) $count('patients'),
            'users' => (int) $count('users'),
            'consultations' => $count('consultations'),
            'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : null,
            'application_version' => is_string($manifest['application_version'] ?? null)
                ? $manifest['application_version']
                : null,
        ];
        $pdo = null;

        return $summary;
    }

    /**
     * A complete, verified archive of the data about to be replaced, kept
     * apart from the rotating backups so retention never removes it.
     *
     * @return array{filename: string, row: array<string, mixed>}
     */
    private function createSafetyBackup(string $operationId): array
    {
        $directory = rtrim((string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        ), '\\/').DIRECTORY_SEPARATOR.self::SAFETY_DIRECTORY;
        $backupId = (string) Str::uuid();
        $startedAt = now();

        try {
            $created = $this->archiveCreator->create(
                $directory,
                'Drclick-Pre-Restore-Safety-'.now()->format('Y-m-d-His').'-'.substr($operationId, 0, 8).'.msbackup',
                $backupId,
            );
        } catch (Throwable $exception) {
            throw new InAppRestoreException(InAppRestoreException::SAFETY_BACKUP_FAILED, $exception);
        }

        return [
            'filename' => $created['filename'],
            'row' => [
                'id' => $backupId,
                'filename' => $created['filename'],
                'disk' => 'local',
                'local_path' => $created['path'],
                'size' => $created['size'],
                'sha256' => $created['sha256'],
                'schema_version' => (int) ($created['manifest']['schema_version'] ?? MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION),
                'application_version' => (string) ($created['manifest']['application_version'] ?? config('medismart.version', 'unknown')),
                'status' => 'completed',
                'started_at' => $startedAt,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
    }

    /**
     * This PC's backup records whose archive is still on disk: they stay
     * listed (and owned by retention) after the restore.
     *
     * @return list<array<string, mixed>>
     */
    private function localBackupRecords(): array
    {
        if (! Schema::hasTable('backup_records')) {
            return [];
        }

        $rows = [];

        foreach (DB::table('backup_records')->whereNotNull('local_path')->get() as $row) {
            $values = (array) $row;

            if (is_string($values['local_path'] ?? null) && is_file($values['local_path'])) {
                $rows[] = $values;
            }
        }

        return $rows;
    }

    /**
     * Documents, scans, medical models and the cabinet's logos. Each active
     * folder is moved aside first, so a failure can put it back untouched.
     *
     * @return list<array{active: string, rollback: string, existed: bool}>
     */
    private function installManagedFiles(string $staging, string $operationId): array
    {
        $stagedRoots = [
            'clinical_documents' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'clinical-documents',
            'patient_documents' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'patient-documents',
            'medical_models' => $staging.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical-models',
            'cabinet' => $staging.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'cabinet',
        ];
        $moved = [];

        try {
            foreach (RestoreTargetSet::fromConfiguration()->managedRoots as $key => $active) {
                if (is_link($active)) {
                    throw new BackupArchiveException('A managed folder is a symbolic link.');
                }

                $this->ensureDirectory(dirname($active));
                $rollback = dirname($active).DIRECTORY_SEPARATOR.'.medismart-in-app-restore-'
                    .$operationId.'-'.$key.'.rollback';
                $existed = file_exists($active);

                if ($existed && ! @rename($active, $rollback)) {
                    throw new BackupArchiveException('A managed folder could not be moved aside.');
                }

                $moved[] = ['active' => $active, 'rollback' => $rollback, 'existed' => $existed];
                $staged = $stagedRoots[$key];

                if (is_dir($staged) && ! is_link($staged)) {
                    if (! @rename($staged, $active)) {
                        // Another volume: copy instead of moving.
                        $this->copyDirectory($staged, $active);
                    }
                } else {
                    $this->ensureDirectory($active);
                }
            }
        } catch (Throwable $exception) {
            $this->rollbackManagedFiles($moved);

            throw $exception;
        }

        return $moved;
    }

    /** @param list<array{active: string, rollback: string, existed: bool}> $moved */
    private function rollbackManagedFiles(array $moved): void
    {
        foreach (array_reverse($moved) as $item) {
            try {
                $this->removeDirectory($item['active']);

                if ($item['existed'] && is_dir($item['rollback'])) {
                    @rename($item['rollback'], $item['active']);
                }
            } catch (Throwable) {
                // The safety backup still holds every document.
            }
        }
    }

    /** @param list<array{active: string, rollback: string, existed: bool}> $moved */
    private function discardRollbacks(array $moved): void
    {
        foreach ($moved as $item) {
            if ($item['existed']) {
                $this->removeDirectory($item['rollback']);
            }
        }
    }

    private function replaceDatabase(string $stagedDatabase, string $live): void
    {
        DB::disconnect();
        $this->copyDatabase($stagedDatabase, $live);
        DB::reconnect();

        // A backup from an older build gets this build's migrations.
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new BackupArchiveException('The restored database could not be migrated.');
        }
    }

    private function restoreDatabase(string $rollback, string $live): void
    {
        try {
            DB::disconnect();

            if (is_file($rollback)) {
                $this->copyDatabase($rollback, $live);
            }
        } catch (Throwable) {
            // Reported through the original failure; the safety backup holds the data.
        } finally {
            DB::reconnect();
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

    /**
     * This PC's own settings rows (installation identity, machine seed,
     * trusted time, backup folder, native desktop preferences…).
     *
     * @return list<array<string, mixed>>
     */
    private function machineBoundSettings(): array
    {
        $rows = [];

        foreach (DB::table('application_settings')->whereIn('key', $this->machineBoundKeys())->get() as $row) {
            $values = (array) $row;
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

    /**
     * @param  list<array<string, mixed>>  $machineSettings
     * @param  list<array<string, mixed>>  $backupRecords
     */
    private function adoptOnThisMachine(array $machineSettings, array $backupRecords): void
    {
        DB::transaction(function () use ($machineSettings, $backupRecords): void {
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

            if (Schema::hasTable('backup_records')) {
                $this->reconcileBackupRecords($backupRecords);
            }
        });
    }

    /**
     * The archives on this PC are what they are now, not what the backup
     * remembers: records of archives deleted since are dropped (unless Drive
     * still holds the copy), and this PC's current archives stay listed.
     *
     * @param  list<array<string, mixed>>  $backupRecords
     */
    private function reconcileBackupRecords(array $backupRecords): void
    {
        DB::table('backup_records')
            ->orderBy('id')
            ->get(['id', 'local_path', 'remote_file_id'])
            ->each(function (object $record): void {
                $onDisk = is_string($record->local_path) && is_file($record->local_path);

                if (! $onDisk && blank($record->remote_file_id)) {
                    DB::table('backup_records')->where('id', $record->id)->delete();
                }
            });
        $columns = array_flip(Schema::getColumnListing('backup_records'));

        foreach ($backupRecords as $row) {
            $row = array_intersect_key($row, $columns);

            if (! isset($row['id']) || DB::table('backup_records')->where('id', $row['id'])->exists()) {
                continue;
            }

            if (isset($row['created_by'])
                && ! DB::table('users')->where('id', $row['created_by'])->exists()) {
                $row['created_by'] = null;
            }

            DB::table('backup_records')->insert($row);
        }
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

    private function resetRuntimeState(): void
    {
        try {
            Cache::flush();
        } catch (Throwable) {
            // The database cache table was already emptied.
        }

        try {
            // Long-running workers still hold state from the replaced database.
            Artisan::call('queue:restart');
        } catch (Throwable) {
        }
    }

    /** @param array{summary: array<string, mixed>, archive_sha256: string} $prepared */
    private function recordHistory(array $prepared, string $safetyBackup, ?User $actor): void
    {
        try {
            $userId = $actor !== null && DB::table('users')->where('id', $actor->getKey())->exists()
                ? $actor->getKey()
                : null;
            AuditLog::record('backup.restored', metadata: [
                'patients' => $prepared['summary']['patients'] ?? null,
                'users' => $prepared['summary']['users'] ?? null,
                'backup_created_at' => $prepared['summary']['created_at'] ?? null,
                'backup_application_version' => $prepared['summary']['application_version'] ?? null,
                'archive_sha256' => $prepared['archive_sha256'],
                'safety_backup' => $safetyBackup,
            ], userId: $userId);
            ApplicationEvent::record('BackupRestored', context: [
                'patients' => $prepared['summary']['patients'] ?? null,
                'archive_sha256' => $prepared['archive_sha256'],
            ]);
        } catch (Throwable) {
            // The clinic's data is in place; missing history must not undo it.
        }
    }

    /** @param array<string, mixed> $prepared */
    private function writePrepared(string $workspace, array $prepared): void
    {
        $path = $workspace.DIRECTORY_SEPARATOR.'prepared.json';

        if (@file_put_contents($path, json_encode($prepared, JSON_THROW_ON_ERROR)) === false) {
            throw new InAppRestoreException(InAppRestoreException::APPLY_FAILED);
        }
    }

    /**
     * @return array{operation_id: string, prepared_at: int, archive_sha256: string, manifest: array<string, mixed>, summary: array<string, mixed>}
     */
    private function readPrepared(string $workspace, string $operationId): array
    {
        try {
            $prepared = json_decode(
                (string) @file_get_contents($workspace.DIRECTORY_SEPARATOR.'prepared.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new InAppRestoreException(InAppRestoreException::EXPIRED);
        }

        if (! is_array($prepared)
            || ($prepared['operation_id'] ?? null) !== $operationId
            || ! is_int($prepared['prepared_at'] ?? null)
            || time() - $prepared['prepared_at'] > self::PREPARED_TTL_SECONDS
            || ! is_string($prepared['archive_sha256'] ?? null)
            || ! is_array($prepared['manifest'] ?? null)
            || ! is_array($prepared['summary'] ?? null)) {
            throw new InAppRestoreException(InAppRestoreException::EXPIRED);
        }

        return $prepared;
    }

    private function workspaceRoot(): string
    {
        return storage_path('app/private/restore-work');
    }

    private function workspacePath(string $operationId): ?string
    {
        if (! Str::isUuid($operationId)) {
            return null;
        }

        $path = $this->workspaceRoot().DIRECTORY_SEPARATOR.self::WORKSPACE_PREFIX.strtolower($operationId);

        return is_dir($path) && ! is_link($path) ? $path : null;
    }

    private function createWorkspace(string $operationId): string
    {
        $root = $this->workspaceRoot();
        $this->ensureDirectory($root);
        $workspace = $root.DIRECTORY_SEPARATOR.self::WORKSPACE_PREFIX.$operationId;

        if (! @mkdir($workspace, 0700)) {
            throw new InAppRestoreException(InAppRestoreException::APPLY_FAILED);
        }

        return $workspace;
    }

    /** Prepared archives nobody restored are not kept around. */
    private function pruneStaleWorkspaces(): void
    {
        try {
            foreach (glob($this->workspaceRoot().DIRECTORY_SEPARATOR.self::WORKSPACE_PREFIX.'*') ?: [] as $workspace) {
                $modified = @filemtime($workspace);

                if (is_dir($workspace) && ! is_link($workspace)
                    && is_int($modified) && time() - $modified > self::PREPARED_TTL_SECONDS) {
                    $this->removeDirectory($workspace);
                }
            }
        } catch (Throwable) {
            // Housekeeping only.
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new InAppRestoreException(InAppRestoreException::APPLY_FAILED);
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
            // Left for the next prune.
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
