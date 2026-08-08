<?php

namespace App\Services;

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\CloudConnection;
use App\Models\User;
use App\Services\Backups\GoogleDriveBackup;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stable application boundary around the Google Drive adapter. Encrypted
 * uploads are performed only by the verified queued-artifact path below;
 * retention remains a separate phase.
 */
final class GoogleDriveService
{
    private const ENCRYPTED_ARCHIVE_MAGIC = "MEDISMART-MSBAK\x02";

    public function __construct(
        private readonly GoogleDriveBackup $legacyAdapter,
        private readonly GoogleDriveOAuthFlow $oauth,
    ) {}

    public function isConfigured(): bool
    {
        return $this->oauth->available();
    }

    /** @return array<string, mixed> */
    public function status(CabinetSetting $cabinet): array
    {
        $cloud = CloudConnection::query()->where('provider', 'google_drive')->first();

        return [
            ...$this->legacyAdapter->status($cabinet),
            'google_drive_configured' => $this->isConfigured(),
            'verification_state' => match ($cloud?->status) {
                'connected' => 'verified',
                'error' => 'failed',
                default => 'not_tested',
            },
            'verification_checked_at' => $cloud?->last_connected_at?->toIso8601String(),
        ];
    }

    /** @return array{authorization_url: string} */
    public function prepareAuthorization(CabinetSetting $cabinet, User $actor): array
    {
        return $this->oauth->prepare($cabinet, $actor);
    }

    public function completeAuthorization(Request $request): void
    {
        $this->oauth->complete($request);
    }

    public function disconnect(CabinetSetting $cabinet): bool
    {
        return $this->legacyAdapter->disconnect($cabinet);
    }

    /** @return array{email: string|null, checked_at: string} */
    public function testConnection(CabinetSetting $cabinet): array
    {
        return $this->legacyAdapter->testConnection($cabinet);
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256_hint: string,
     *     backup_record_id: string
     * }>
     */
    public function listBackups(CabinetSetting $cabinet): array
    {
        return $this->legacyAdapter->listBackups($cabinet);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function retentionInventory(CabinetSetting $cabinet): array
    {
        return $this->legacyAdapter->retentionInventory($cabinet);
    }

    public function downloadVerifiedArchive(
        CabinetSetting $cabinet,
        string $fileId,
        ?User $actor,
    ): BackupRecord {
        $backupRoot = (string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        );

        if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0700, true) && ! is_dir($backupRoot)) {
            throw new RuntimeException('The private backup directory is unavailable.');
        }

        @chmod($backupRoot, 0700);
        $canonicalRoot = realpath($backupRoot);

        if (! is_string($canonicalRoot) || is_link($backupRoot)) {
            throw new RuntimeException('The private backup directory is unsafe.');
        }

        $temporaryPath = $canonicalRoot.DIRECTORY_SEPARATOR.'.drive-'.Str::uuid().'.part';
        $publishedPath = null;
        $startedAt = now();

        try {
            $metadata = $this->legacyAdapter->downloadVerifiedArchive(
                $cabinet,
                $fileId,
                $temporaryPath,
            );
            $filename = $this->downloadedFilename($metadata['name']);
            $publishedPath = $canonicalRoot.DIRECTORY_SEPARATOR.$filename;

            if (file_exists($publishedPath)
                || is_link($publishedPath)
                || ! rename($temporaryPath, $publishedPath)
                || ! chmod($publishedPath, 0600)) {
                throw new RuntimeException('The verified Drive backup could not be published locally.');
            }

            $record = DB::transaction(function () use (
                $metadata,
                $filename,
                $publishedPath,
                $startedAt,
                $actor,
            ): BackupRecord {
                $record = BackupRecord::query()->create([
                    'filename' => $filename,
                    'disk' => 'local',
                    'local_path' => $publishedPath,
                    'remote_file_id' => $metadata['id'],
                    'size' => $metadata['size_bytes'],
                    'sha256' => $metadata['sha256'],
                    'application_version' => (string) config('medismart.version', 'unknown'),
                    'status' => 'completed',
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'created_by' => $actor?->getKey(),
                ]);
                AuditLog::record('backup.drive_downloaded', $record, [
                    'provider' => 'google_drive',
                    'source_backup_record_id' => $metadata['backup_record_id'],
                    'size' => $metadata['size_bytes'],
                    'sha256' => $metadata['sha256'],
                ], $actor?->getKey());
                ApplicationEvent::record('BackupDriveDownloadCompleted', context: [
                    'backup_record_id' => $record->getKey(),
                    'source_backup_record_id' => $metadata['backup_record_id'],
                    'size' => $metadata['size_bytes'],
                ]);

                return $record;
            });

            return $record->refresh();
        } catch (Throwable $exception) {
            foreach ([$temporaryPath, $publishedPath] as $path) {
                if (is_string($path)
                    && str_starts_with($path, $canonicalRoot.DIRECTORY_SEPARATOR)
                    && is_file($path)
                    && ! is_link($path)) {
                    @unlink($path);
                }
            }

            throw new RuntimeException(
                'The Google Drive backup could not be downloaded and verified.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     backup_record_id: string,
     *     newer_backup_record_id: string
     * }
     */
    public function deleteManagedBackup(CabinetSetting $cabinet, string $fileId): array
    {
        return $this->legacyAdapter->deleteManagedBackup($cabinet, $fileId);
    }

    /**
     * Re-open and authenticate all non-secret artifact metadata immediately
     * before the adapter is allowed to make a Google request.
     *
     * @return array{id: string, name: string}
     */
    public function uploadCompletedArchive(
        CabinetSetting $cabinet,
        BackupRecord $record,
        string $folderName,
        ?Closure $progress = null,
    ): array {
        if (! $this->legacyAdapter->status($cabinet)['google_drive_connected']) {
            throw new InvalidArgumentException('The Google Drive connection is no longer available.');
        }

        $stream = $this->openVerifiedArchive($record);

        try {
            return $this->legacyAdapter->uploadVerifiedArchive(
                $cabinet,
                $stream,
                [
                    'backup_record_id' => (string) $record->getKey(),
                    'filename' => (string) $record->filename,
                    'size' => (int) $record->size,
                    'sha256' => (string) $record->sha256,
                    'format' => 'msbackup',
                    'format_version' => 2,
                ],
                $folderName,
                $progress,
            );
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    public function recordUploadFailure(CabinetSetting $cabinet): void
    {
        $this->legacyAdapter->recordUploadFailure($cabinet);
    }

    /** @return resource */
    private function openVerifiedArchive(BackupRecord $record)
    {
        $filename = $record->filename;
        $recordedPath = $record->local_path;
        $expectedSize = $record->size;
        $expectedSha256 = $record->sha256;

        if ($record->status !== 'completed'
            || $record->completed_at === null
            || $record->disk !== 'local'
            || $filename === ''
            || basename(str_replace('\\', '/', $filename)) !== $filename
            || ! str_ends_with($filename, '.msbackup')
            || ! is_string($recordedPath)
            || $recordedPath === ''
            || str_contains($recordedPath, "\0")
            || ! is_int($expectedSize)
            || $expectedSize <= strlen(self::ENCRYPTED_ARCHIVE_MAGIC)
            || ! is_string($expectedSha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
            throw $this->invalidArtifact();
        }

        $backupRoot = realpath((string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        ));
        $canonicalPath = realpath($recordedPath);
        $normalizedRecordedPath = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $recordedPath),
            DIRECTORY_SEPARATOR,
        );

        if (! is_string($backupRoot)
            || ! is_string($canonicalPath)
            || is_link($recordedPath)
            || $canonicalPath !== $normalizedRecordedPath
            || ! str_starts_with($canonicalPath, rtrim($backupRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || basename($canonicalPath) !== $filename
            || ! is_file($canonicalPath)) {
            throw $this->invalidArtifact();
        }

        $stream = fopen($canonicalPath, 'rb');

        if (! is_resource($stream) || ! flock($stream, LOCK_SH)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $this->invalidArtifact();
        }

        try {
            $stat = fstat($stream);

            if (! is_array($stat)
                || ($stat['mode'] & 0170000) !== 0100000
                || $stat['size'] !== $expectedSize) {
                throw $this->invalidArtifact();
            }

            $magic = fread($stream, strlen(self::ENCRYPTED_ARCHIVE_MAGIC));

            if ($magic !== self::ENCRYPTED_ARCHIVE_MAGIC || rewind($stream) === false) {
                throw $this->invalidArtifact();
            }

            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualSha256 = hash_final($hash);

            if (! hash_equals($expectedSha256, $actualSha256) || rewind($stream) === false) {
                throw $this->invalidArtifact();
            }

            return $stream;
        } catch (Throwable $exception) {
            flock($stream, LOCK_UN);
            fclose($stream);

            throw $exception;
        }
    }

    private function invalidArtifact(): InvalidArgumentException
    {
        return new InvalidArgumentException('The completed encrypted backup artifact failed verification.');
    }

    private function downloadedFilename(string $remoteName): string
    {
        $stem = pathinfo($remoteName, PATHINFO_FILENAME);
        $safeStem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem);
        $safeStem = is_string($safeStem) ? trim($safeStem, '.-_') : '';

        if ($safeStem === '') {
            $safeStem = 'Drclick-Backup';
        }

        return Str::limit($safeStem, 170, '')
            .'-Drive-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.msbackup';
    }
}
