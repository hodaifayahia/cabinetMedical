<?php

namespace App\Services;

use App\Backups\EncryptedMsBackupArchive;
use App\Backups\LocalSqliteBackup;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Records the existing SQLite snapshot workflow behind a stable boundary.
 * Versioned .msbackup archives and atomic restore replace this legacy adapter
 * in the backup/restore phase.
 */
final class BackupService
{
    public function __construct(
        private readonly LocalSqliteBackup $legacySqliteBackup,
        private readonly MsBackupArchiveCreator $archiveCreator,
        private readonly EncryptedMsBackupArchive $encryptedArchive,
    ) {}

    /**
     * Create an authenticated, passphrase-portable v2 archive. The temporary
     * verified v1 archive is removed before this method returns and is never
     * published as the requested backup.
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     envelope: array<string, mixed>,
     *     manifest: array<string, mixed>,
     *     record: BackupRecord
     * }
     */
    public function createEncryptedArchive(
        #[SensitiveParameter] string $passphrase,
        ?User $actor = null,
        ?string $destinationDirectory = null,
        ?MsBackupEncryptionParameters $parameters = null,
        ?string $workingDirectory = null,
    ): array {
        $startedAt = now();
        $operationId = (string) Str::uuid();
        $workDirectory = $workingDirectory ?? storage_path('app/private/backup-work');
        $destinationDirectory ??= (string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        );
        $filename = 'Drclick-Backup-'.now()->format('Y-m-d-His')
            .'-'.Str::lower(Str::random(6)).'.msbackup';
        $plain = null;
        $encrypted = null;

        try {
            $this->ensureDirectory($workDirectory, 0700);
            $this->ensureDirectory($destinationDirectory, 0750);
            $plain = $this->archiveCreator->create(
                $workDirectory,
                $filename,
                $operationId,
            );
            $encrypted = $this->encryptedArchive->encrypt(
                $plain['path'],
                rtrim($destinationDirectory, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR.$filename,
                $passphrase,
                $parameters,
            );

            if (! @unlink($plain['path']) && is_file($plain['path'])) {
                throw new \RuntimeException('The temporary plaintext backup could not be removed.');
            }

            $plain = null;
            $record = DB::transaction(function () use (
                $actor,
                $encrypted,
                $operationId,
                $startedAt,
            ): BackupRecord {
                ApplicationEvent::record('BackupStarted', context: [
                    'operation_id' => $operationId,
                    'format' => 'msbackup',
                    'encrypted' => true,
                    'started_at' => $startedAt->toIso8601String(),
                ]);

                $record = BackupRecord::query()->create([
                    'filename' => $encrypted['filename'],
                    'disk' => 'local',
                    'local_path' => $encrypted['path'],
                    'size' => $encrypted['size'],
                    'sha256' => $encrypted['sha256'],
                    'application_version' => (string) $encrypted['manifest']['application_version'],
                    'schema_version' => (int) $encrypted['manifest']['schema_version'],
                    'status' => 'completed',
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'created_by' => $actor?->getKey(),
                ]);

                AuditLog::record('backup.created', $record, [
                    'format' => 'msbackup',
                    'format_version' => 2,
                    'encrypted' => true,
                    'encryption_profile' => $encrypted['envelope']['encryption']['profile'] ?? null,
                    'size' => $encrypted['size'],
                    'sha256' => $encrypted['sha256'],
                    'components' => $encrypted['manifest']['components'],
                ], $actor?->getKey());
                ApplicationEvent::record('BackupCompleted', context: [
                    'operation_id' => $operationId,
                    'backup_record_id' => $record->getKey(),
                    'format' => 'msbackup',
                    'encrypted' => true,
                ]);

                return $record;
            });

            return [...$encrypted, 'record' => $record->refresh()];
        } catch (Throwable $exception) {
            foreach ([$plain['path'] ?? null, $encrypted['path'] ?? null] as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }

            $this->recordEncryptedFailure($exception, $actor, $operationId, $startedAt);

            throw $exception;
        }
    }

    /**
     * Create the versioned, checksummed archive used by the managed backup
     * workflow. Existing controllers deliberately remain on the legacy method
     * until the archive download/restore UI is introduced.
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     manifest: array<string, mixed>,
     *     entry_count: int,
     *     record: BackupRecord
     * }
     */
    public function createArchive(?User $actor = null, ?string $destinationDirectory = null): array
    {
        $startedAt = now();
        $operationId = (string) Str::uuid();
        $archive = null;

        try {
            $archive = $this->archiveCreator->create($destinationDirectory, backupId: $operationId);
            $record = DB::transaction(function () use ($actor, $archive, $operationId, $startedAt): BackupRecord {
                ApplicationEvent::record('BackupStarted', context: [
                    'operation_id' => $operationId,
                    'format' => 'msbackup',
                    'started_at' => $startedAt->toIso8601String(),
                ]);

                $record = BackupRecord::query()->create([
                    'filename' => $archive['filename'],
                    'disk' => 'local',
                    'local_path' => $archive['path'],
                    'size' => $archive['size'],
                    'sha256' => $archive['sha256'],
                    'application_version' => (string) $archive['manifest']['application_version'],
                    'schema_version' => (int) $archive['manifest']['schema_version'],
                    'status' => 'completed',
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'created_by' => $actor?->getKey(),
                ]);

                AuditLog::record('backup.created', $record, [
                    'format' => 'msbackup',
                    'size' => $archive['size'],
                    'sha256' => $archive['sha256'],
                    'format_version' => $archive['manifest']['format_version'],
                    'components' => $archive['manifest']['components'],
                ], $actor?->getKey());
                ApplicationEvent::record('BackupCompleted', context: [
                    'operation_id' => $operationId,
                    'backup_record_id' => $record->getKey(),
                    'format' => 'msbackup',
                ]);

                return $record;
            });

            return [...$archive, 'record' => $record->refresh()];
        } catch (Throwable $exception) {
            if ($archive !== null && is_file($archive['path'])) {
                @unlink($archive['path']);
            }

            try {
                $record = BackupRecord::query()->create([
                    'filename' => 'Drclick-Backup-failed-'.Str::lower(Str::random(8)).'.msbackup',
                    'disk' => 'local',
                    'application_version' => (string) config('medismart.version', 'unknown'),
                    'schema_version' => MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION,
                    'status' => 'failed',
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'failure_message' => AuditLog::redactSensitiveText(
                        Str::limit($exception->getMessage(), 2000, ''),
                    ),
                    'created_by' => $actor?->getKey(),
                ]);
                AuditLog::record('backup.failed', $record, [
                    'format' => 'msbackup',
                    'operation_id' => $operationId,
                ], $actor?->getKey());
                ApplicationEvent::record('BackupFailed', 'error', context: [
                    'operation_id' => $operationId,
                    'backup_record_id' => $record->getKey(),
                    'format' => 'msbackup',
                ]);
            } catch (Throwable) {
                // Preserve the archive failure if operation history is unavailable.
            }

            throw $exception;
        }
    }

    /**
     * @return array{path: string, filename: string, record: BackupRecord}
     */
    public function createLegacySnapshot(?User $actor = null): array
    {
        // Do not mutate SQLite before VACUUM INTO: otherwise the snapshot
        // contains a permanently stale "running" backup record.
        $startedAt = now();
        $schemaVersion = $this->schemaVersion();

        try {
            $snapshot = $this->legacySqliteBackup->create();
            $size = filesize($snapshot['path']);
            $checksum = hash_file('sha256', $snapshot['path']);

            if ($size === false || $checksum === false) {
                throw new \RuntimeException('The backup snapshot could not be verified.');
            }

            $record = BackupRecord::query()->create([
                'filename' => $snapshot['filename'],
                'disk' => 'local',
                'local_path' => $snapshot['path'],
                'size' => $size,
                'sha256' => $checksum,
                'application_version' => (string) config('medismart.version', 'unknown'),
                'schema_version' => $schemaVersion,
                'status' => 'completed',
                'started_at' => $startedAt,
                'completed_at' => now(),
                'created_by' => $actor?->getKey(),
            ]);

            ApplicationEvent::record('BackupStarted', context: [
                'backup_record_id' => $record->getKey(),
                'format' => 'legacy_sqlite',
                'started_at' => $startedAt->toIso8601String(),
            ]);
            AuditLog::record('backup.created', $record, [
                'format' => 'legacy_sqlite',
                'size' => $size,
                'sha256' => $checksum,
            ], $actor?->getKey());
            ApplicationEvent::record('BackupCompleted', context: [
                'backup_record_id' => $record->getKey(),
            ]);

            return [...$snapshot, 'record' => $record->refresh()];
        } catch (Throwable $exception) {
            try {
                $record = BackupRecord::query()->create([
                    'filename' => 'legacy-failed-'.Str::lower(Str::random(8)).'.sqlite3',
                    'disk' => 'local',
                    'application_version' => (string) config('medismart.version', 'unknown'),
                    'schema_version' => $schemaVersion,
                    'status' => 'failed',
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'failure_message' => AuditLog::redactSensitiveText(
                        Str::limit($exception->getMessage(), 2000, ''),
                    ),
                    'created_by' => $actor?->getKey(),
                ]);
                ApplicationEvent::record('BackupFailed', 'error', context: [
                    'backup_record_id' => $record->getKey(),
                    'error' => Str::limit($exception->getMessage(), 500, ''),
                ]);
            } catch (Throwable) {
                // Preserve the original failure if the database itself is down.
            }

            throw $exception;
        }
    }

    public function restoreLegacySnapshot(UploadedFile $file, ?User $actor = null): void
    {
        AuditLog::record('restore.legacy_started', metadata: [
            'size' => $file->getSize(),
        ], userId: $actor?->getKey());
        ApplicationEvent::record('RestoreStarted', context: ['format' => 'legacy_sqlite']);

        try {
            $this->legacySqliteBackup->restore($file);
            AuditLog::record('restore.legacy_completed', userId: $actor?->getKey());
            ApplicationEvent::record('RestoreCompleted', context: ['format' => 'legacy_sqlite']);
        } catch (Throwable $exception) {
            ApplicationEvent::record('RestoreFailed', 'error', context: [
                'format' => 'legacy_sqlite',
                'error' => Str::limit($exception->getMessage(), 500, ''),
            ]);

            throw $exception;
        }
    }

    private function schemaVersion(): int
    {
        return DB::table('migrations')->count();
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if ((! is_dir($directory) && ! mkdir($directory, $mode, true))
            || ! is_dir($directory)
            || ! is_writable($directory)) {
            throw new \RuntimeException('The backup working directory is unavailable.');
        }

        @chmod($directory, $mode);
    }

    private function recordEncryptedFailure(
        Throwable $exception,
        ?User $actor,
        string $operationId,
        CarbonInterface $startedAt,
    ): void {
        try {
            $record = BackupRecord::query()->create([
                'filename' => 'Drclick-Backup-failed-'.Str::lower(Str::random(8)).'.msbackup',
                'disk' => 'local',
                'application_version' => (string) config('medismart.version', 'unknown'),
                'schema_version' => MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION,
                'status' => 'failed',
                'started_at' => $startedAt,
                'completed_at' => now(),
                'failure_message' => AuditLog::redactSensitiveText(
                    Str::limit($exception->getMessage(), 2000, ''),
                ),
                'created_by' => $actor?->getKey(),
            ]);
            AuditLog::record('backup.failed', $record, [
                'format' => 'msbackup',
                'format_version' => 2,
                'encrypted' => true,
                'operation_id' => $operationId,
            ], $actor?->getKey());
            ApplicationEvent::record('BackupFailed', 'error', context: [
                'operation_id' => $operationId,
                'backup_record_id' => $record->getKey(),
                'format' => 'msbackup',
                'encrypted' => true,
            ]);
        } catch (Throwable) {
            // Preserve the encryption failure if operation history is unavailable.
        }
    }
}
