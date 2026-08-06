<?php

namespace App\Backups;

use App\Services\MachineFingerprintService;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SensitiveParameter;
use SplFileInfo;
use Throwable;

final class OfflineRestorePreparer
{
    public function __construct(
        private readonly EncryptedMsBackupArchive $encryptedArchive,
        private readonly StagedMsBackupExtractor $extractor,
        private readonly StagedSqliteValidator $databaseValidator,
        private readonly MachineFingerprintService $machine,
    ) {}

    public function prepare(
        string $encryptedArchivePath,
        #[SensitiveParameter] string $passphrase,
        ?string $operationId = null,
    ): PreparedRestore {
        return $this->preparePath(
            $encryptedArchivePath,
            $passphrase,
            $operationId,
            requireSourceExtension: true,
        );
    }

    /**
     * Prepare an HTTP-upload temporary file whose framework-owned path does
     * not preserve the validated client `.msbackup` extension. Operation IDs
     * remain server-generated so a request cannot select or overwrite a
     * native authorization workspace.
     */
    public function prepareUploadedArchive(
        string $uploadedTemporaryPath,
        #[SensitiveParameter] string $passphrase,
    ): PreparedRestore {
        return $this->preparePath(
            $uploadedTemporaryPath,
            $passphrase,
            operationId: null,
            requireSourceExtension: false,
        );
    }

    private function preparePath(
        string $encryptedArchivePath,
        #[SensitiveParameter] string $passphrase,
        ?string $operationId,
        bool $requireSourceExtension,
    ): PreparedRestore {
        $operationId ??= (string) Str::uuid();

        if (! Str::isUuid($operationId)) {
            throw new BackupArchiveException('The restore operation identifier is invalid.');
        }

        $workspace = $this->createWorkspace($operationId);
        $journal = RestoreRecoveryJournal::create($operationId);
        $innerArchivePath = $workspace.DIRECTORY_SEPARATOR.'authenticated-inner.msbackup';

        try {
            $decrypted = $this->encryptedArchive->decrypt(
                $encryptedArchivePath,
                $innerArchivePath,
                $passphrase,
                $requireSourceExtension,
            );
            $manifest = $decrypted['manifest'];
            $installationId = $manifest['installation_id'] ?? null;
            $currentInstallationId = $this->machine->installationId();

            if (! is_string($installationId)
                || ! hash_equals($currentInstallationId, $installationId)) {
                throw new BackupArchiveException(
                    'This backup belongs to another installation. Cross-install restore is disabled while application secrets remain source-key-bound.',
                );
            }

            $journal->append('archive_authenticated', [
                'encrypted_sha256' => $decrypted['archive_sha256'],
                'inner_sha256' => $decrypted['plaintext_sha256'],
                'format_version' => EncryptedMsBackupEnvelope::FORMAT_VERSION,
            ]);
            $stagingRoot = $workspace.DIRECTORY_SEPARATOR.'staged';
            $this->ensurePrivateDirectory($stagingRoot);
            $extracted = $this->extractor->extract($innerArchivePath, $stagingRoot);

            if (! hash_equals($decrypted['plaintext_sha256'], $extracted['archive_sha256'])
                || $manifest !== $extracted['manifest']) {
                throw new BackupArchiveException('The staged restore archive identity changed unexpectedly.');
            }

            $journal->append('payload_extracted', [
                'file_count' => $extracted['file_count'],
                'bytes' => $extracted['bytes'],
            ]);
            $migrations = $this->databaseValidator->validate(
                $stagingRoot.DIRECTORY_SEPARATOR.'database.sqlite3',
                $manifest,
            );
            $journal->append('staging_validated', [
                'migration_count' => count($migrations),
                'schema_version' => MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION,
            ]);
            $prepared = PreparedRestore::publish(
                $operationId,
                $workspace,
                $journal->path,
                $decrypted['archive_sha256'],
                $decrypted['plaintext_sha256'],
                $manifest,
                $extracted['file_count'],
                $extracted['bytes'],
                $extracted['inventory'],
            );

            if (! @unlink($innerArchivePath)) {
                throw new BackupArchiveException('The authenticated plaintext archive could not be removed after staging.');
            }

            $journal->append('ready_for_offline_apply', [
                'plan_sha256' => $prepared->planSha256,
                'web_apply_enabled' => false,
            ]);

            return $prepared;
        } catch (Throwable $exception) {
            try {
                $journal->append('preparation_failed', [
                    'reason_code' => $this->failureCode($exception),
                    'web_apply_enabled' => false,
                ]);
            } catch (Throwable) {
                // Preserve the original failure. A missing final journal event
                // is itself detected as an incomplete recovery record.
            }

            $this->removeWorkspace($workspace);

            if ($exception instanceof BackupArchiveException) {
                throw $exception;
            }

            throw new BackupArchiveException('The encrypted backup could not be prepared for offline restore.');
        }
    }

    private function createWorkspace(string $operationId): string
    {
        $root = storage_path('app/private/restore-work');
        $this->ensurePrivateDirectory($root);
        $workspace = $root.DIRECTORY_SEPARATOR.$operationId;

        if (file_exists($workspace) || is_link($workspace)
            || ! @mkdir($workspace, 0700)) {
            throw new BackupArchiveException('The restore staging workspace could not be created.');
        }

        $this->ensurePrivateDirectory($workspace);

        return $workspace;
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && (! @mkdir($directory, 0700, true) && ! is_dir($directory)))
            || ! is_writable($directory)) {
            throw new BackupArchiveException('A private restore staging directory is unavailable.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new BackupArchiveException('A private restore staging directory could not be secured.');
        }
    }

    private function removeWorkspace(string $workspace): void
    {
        $root = realpath(storage_path('app/private/restore-work'));
        $resolved = realpath($workspace);

        if (! is_string($root) || ! is_string($resolved)
            || dirname($resolved) !== rtrim($root, DIRECTORY_SEPARATOR)
            || ! Str::isUuid(basename($resolved)) || is_link($workspace)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isLink()) {
                @unlink($path);
            } elseif ($entry->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($resolved);
    }

    private function failureCode(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'authenticated') => 'authentication_failed',
            str_contains($message, 'another installation') => 'installation_mismatch',
            str_contains($message, 'migration') => 'migration_incompatible',
            str_contains($message, 'integrity') || str_contains($message, 'checksum') => 'integrity_failed',
            default => 'preparation_failed',
        };
    }
}
