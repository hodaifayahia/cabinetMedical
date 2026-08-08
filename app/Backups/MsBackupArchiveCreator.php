<?php

namespace App\Backups;

use App\Services\MachineFingerprintService;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class MsBackupArchiveCreator
{
    public const FORMAT_IDENTIFIER = 'medismart-backup';

    public const FORMAT_VERSION = 1;

    /**
     * Restore compatibility version. Bump deliberately when a database can no
     * longer be restored by the matching application restore implementation;
     * this is intentionally independent of the migration row count.
     */
    public const DATABASE_SCHEMA_VERSION = 1;

    private const MINIMUM_FREE_SPACE_OVERHEAD = 1024 * 1024;

    public function __construct(
        private readonly MachineFingerprintService $machine,
        private readonly BackupArchiveVerifier $verifier,
    ) {}

    /**
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     manifest: array<string, mixed>,
     *     entry_count: int
     * }
     */
    public function create(
        ?string $destinationDirectory = null,
        ?string $filename = null,
        ?string $backupId = null,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupArchiveException('The ZIP extension is required to create DrClickDz backups.');
        }

        if (config('database.default') !== 'sqlite') {
            throw new BackupArchiveException('DrClickDz archives currently require an SQLite database.');
        }

        if (PHP_INT_SIZE < 8) {
            throw new BackupArchiveException('DrClickDz archives require a 64-bit PHP runtime.');
        }

        $destination = $this->writableDestination(
            $destinationDirectory ?? (string) config(
                'medismart.backups.managed_directory',
                storage_path('app/private/backups'),
            ),
        );
        $filename ??= 'MediSmart-Backup-'.now()->format('Y-m-d-His').'-'.Str::lower(Str::random(6)).'.msbackup';
        $this->assertFilename($filename);

        $finalPath = $destination.DIRECTORY_SEPARATOR.$filename;

        if (file_exists($finalPath)) {
            throw new BackupArchiveException('A backup with the selected filename already exists.');
        }

        $privateRoot = $this->localDiskRoot('local', storage_path('app/private'));
        $publicRoot = $this->localDiskRoot('public', storage_path('app/public'));
        $this->assertDestinationOutsideManagedRoots($destination, $privateRoot, $publicRoot);
        // This preflight scan is only a conservative space estimate. It is
        // deliberately discarded: the authoritative asset inventory is made
        // after the consistent SQLite snapshot exists.
        $estimatedAssetBytes = array_sum(array_column(
            $this->managedAssets($privateRoot, $publicRoot),
            'size',
        ));
        $estimatedDatabaseBytes = $this->estimatedDatabaseBytes();
        $overhead = max(
            self::MINIMUM_FREE_SPACE_OVERHEAD,
            (int) ceil(($estimatedDatabaseBytes + $estimatedAssetBytes) * 0.05),
        );

        // The destination must hold both the VACUUM snapshot and the archive
        // until the verified temporary archive is atomically published.
        $this->assertFreeSpace(
            $destination,
            ($estimatedDatabaseBytes * 2) + $estimatedAssetBytes + $overhead,
        );

        $operationId = $backupId ?? (string) Str::uuid();

        if (! Str::isUuid($operationId)) {
            throw new BackupArchiveException('The backup operation identifier is invalid.');
        }

        $snapshotPath = $destination.DIRECTORY_SEPARATOR.'.msbackup-'.$operationId.'.sqlite3';
        $temporaryPath = $destination.DIRECTORY_SEPARATOR.'.'.$filename.'.'.$operationId.'.tmp.msbackup';

        try {
            $installationId = $this->machine->installationId();
            $this->createConsistentSnapshot($snapshotPath);

            $snapshotSize = filesize($snapshotPath);

            if (! is_int($snapshotSize)) {
                throw new BackupArchiveException('The SQLite snapshot size could not be read.');
            }

            $assets = $this->managedAssets($privateRoot, $publicRoot);
            $assetBytes = array_sum(array_column($assets, 'size'));
            $overhead = max(
                self::MINIMUM_FREE_SPACE_OVERHEAD,
                (int) ceil(($snapshotSize + $assetBytes) * 0.05),
            );
            $this->assertPayloadLimits($snapshotSize, $assets);
            $this->assertSnapshotReferencesAreIncluded($snapshotPath, $assets);
            $this->assertFreeSpace($destination, $snapshotSize + $assetBytes + $overhead);

            $payloads = $this->checksumPayloads($snapshotPath, $assets);
            $manifest = $this->manifest($operationId, $installationId, $snapshotPath, $payloads);
            $manifestJson = $this->encodeJson($manifest);
            $checksums = [
                'format' => 'medismart-checksums',
                'version' => 1,
                'algorithm' => 'sha256',
                'entries' => [
                    [
                        'path' => 'manifest.json',
                        'sha256' => hash('sha256', $manifestJson),
                        'size' => strlen($manifestJson),
                    ],
                ],
            ];

            foreach ($payloads as $payload) {
                $checksums['entries'][] = [
                    'path' => $payload['entry'],
                    'sha256' => $payload['sha256'],
                    'size' => $payload['size'],
                ];
            }

            usort(
                $checksums['entries'],
                static fn (array $left, array $right): int => $left['path'] <=> $right['path'],
            );
            $checksumsJson = $this->encodeJson($checksums);
            $this->writeArchive($temporaryPath, $manifestJson, $payloads, $checksumsJson);

            $verified = $this->verifier->verify($temporaryPath);

            if (file_exists($finalPath) || ! @rename($temporaryPath, $finalPath)) {
                throw new BackupArchiveException('The verified backup could not be published atomically.');
            }

            @chmod($finalPath, 0640);

            return [
                'path' => $finalPath,
                'filename' => $filename,
                'size' => $verified['archive_size'],
                'sha256' => $verified['archive_sha256'],
                'manifest' => $verified['manifest'],
                'entry_count' => $verified['entry_count'],
            ];
        } finally {
            if (is_file($snapshotPath)) {
                @unlink($snapshotPath);
            }

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function writableDestination(string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new BackupArchiveException('The backup destination is invalid.');
        }

        if (! is_dir($directory)
            && (! mkdir($directory, 0750, true) && ! is_dir($directory))) {
            throw new BackupArchiveException('The backup destination could not be created.');
        }

        $resolved = realpath($directory);

        if (! is_string($resolved) || ! is_dir($resolved) || ! is_writable($resolved)) {
            throw new BackupArchiveException('The backup destination is not writable.');
        }

        $probe = @tempnam($resolved, '.msbackup-write-');

        if (! is_string($probe) || ! $this->sameDirectory(dirname($probe), $resolved)) {
            if (is_string($probe) && is_file($probe)) {
                @unlink($probe);
            }

            throw new BackupArchiveException('The backup destination is not writable.');
        }

        @unlink($probe);

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function assertFilename(string $filename): void
    {
        BackupArchivePath::assertSafe($filename);

        if (basename($filename) !== $filename
            || Str::lower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'msbackup') {
            throw new BackupArchiveException('The backup filename is invalid.');
        }
    }

    private function localDiskRoot(string $disk, string $fallback): string
    {
        $configuration = config("filesystems.disks.{$disk}");
        $root = is_array($configuration) ? ($configuration['root'] ?? $fallback) : $fallback;

        if (! is_string($root) || $root === '') {
            throw new BackupArchiveException('A managed storage root is invalid.');
        }

        return rtrim($root, '\\/');
    }

    /**
     * @return list<array{path: string, entry: string, size: int, component: 'private_storage'|'public_storage'}>
     */
    private function managedAssets(string $privateRoot, string $publicRoot): array
    {
        $assets = [];
        $portableNames = [];

        foreach ([
            [
                'root' => $privateRoot.DIRECTORY_SEPARATOR.'clinical-documents',
                'prefix' => 'storage/private/clinical-documents',
                'component' => 'private_storage',
            ],
            [
                'root' => $privateRoot.DIRECTORY_SEPARATOR.'patient-documents',
                'prefix' => 'storage/private/patient-documents',
                'component' => 'private_storage',
            ],
            [
                'root' => $privateRoot.DIRECTORY_SEPARATOR.'medical-models',
                'prefix' => 'storage/private/medical-models',
                'component' => 'private_storage',
            ],
            [
                'root' => $publicRoot.DIRECTORY_SEPARATOR.'cabinet',
                'prefix' => 'storage/public/cabinet',
                'component' => 'public_storage',
            ],
        ] as $source) {
            if (is_link($source['root'])) {
                throw new BackupArchiveException('A managed storage root cannot be a symbolic link.');
            }

            if (! is_dir($source['root'])) {
                continue;
            }

            $resolvedRoot = realpath($source['root']);

            if (! is_string($resolvedRoot)) {
                throw new BackupArchiveException('A managed storage root could not be resolved.');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source['root'], FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $absolutePath = $file->getPathname();

                if ($file->isLink()) {
                    throw new BackupArchiveException('Managed storage contains a symbolic link that cannot be backed up safely.');
                }

                if (! $file->isFile()) {
                    continue;
                }

                $resolvedPath = realpath($absolutePath);

                if (! is_string($resolvedPath)
                    || ! $this->isWithin($resolvedPath, $resolvedRoot)) {
                    throw new BackupArchiveException('A managed storage file escapes its configured root.');
                }

                $relativePath = substr($absolutePath, strlen($source['root']));
                $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                $entry = $source['prefix'].'/'.$relativePath;
                BackupArchivePath::assertSafe($entry);

                $portableName = BackupArchivePath::portableKey($entry);

                if (isset($portableNames[$portableName])) {
                    throw new BackupArchiveException('Managed storage contains colliding portable filenames.');
                }

                $size = $file->getSize();

                if ($size < 0 || ! is_readable($absolutePath)) {
                    throw new BackupArchiveException('A managed storage file is not readable.');
                }

                $portableNames[$portableName] = true;
                $assets[] = [
                    'path' => $absolutePath,
                    'entry' => $entry,
                    'size' => $size,
                    'component' => $source['component'],
                ];
            }
        }

        usort($assets, static fn (array $left, array $right): int => $left['entry'] <=> $right['entry']);

        return $assets;
    }

    private function isWithin(string $path, string $root): bool
    {
        $normalizedPath = $this->portableFilesystemPath($path);
        $normalizedRoot = rtrim($this->portableFilesystemPath($root), '/');

        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }

    private function portableFilesystemPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private function sameDirectory(string $left, string $right): bool
    {
        return rtrim($this->portableFilesystemPath($left), '/')
            === rtrim($this->portableFilesystemPath($right), '/');
    }

    private function assertDestinationOutsideManagedRoots(
        string $destination,
        string $privateRoot,
        string $publicRoot,
    ): void {
        foreach ([
            $privateRoot.DIRECTORY_SEPARATOR.'clinical-documents',
            $privateRoot.DIRECTORY_SEPARATOR.'patient-documents',
            $privateRoot.DIRECTORY_SEPARATOR.'medical-models',
            $publicRoot.DIRECTORY_SEPARATOR.'cabinet',
        ] as $managedRoot) {
            $resolvedRoot = realpath($managedRoot);

            if (is_string($resolvedRoot) && $this->isWithin($destination, $resolvedRoot)) {
                throw new BackupArchiveException('The backup destination cannot be inside managed storage.');
            }
        }
    }

    /**
     * @param  list<array{path: string, entry: string, size: int, component: 'private_storage'|'public_storage'}>  $assets
     */
    private function assertPayloadLimits(int $snapshotSize, array $assets): void
    {
        if (count($assets) + 3 > MsBackupArchiveVerifier::MAXIMUM_ENTRIES
            || $snapshotSize > MsBackupArchiveVerifier::MAXIMUM_ENTRY_BYTES) {
            throw new BackupArchiveException('The backup payload exceeds archive safety limits.');
        }

        $total = $snapshotSize;

        foreach ($assets as $asset) {
            if ($asset['size'] > MsBackupArchiveVerifier::MAXIMUM_ENTRY_BYTES
                || $total > MsBackupArchiveVerifier::MAXIMUM_UNCOMPRESSED_BYTES - $asset['size']) {
                throw new BackupArchiveException('The backup payload exceeds archive safety limits.');
            }

            $total += $asset['size'];
        }
    }

    private function estimatedDatabaseBytes(): int
    {
        try {
            $pdo = DB::connection()->getPdo();
            $pageCountStatement = $pdo->query('PRAGMA page_count');
            $pageSizeStatement = $pdo->query('PRAGMA page_size');

            if ($pageCountStatement === false || $pageSizeStatement === false) {
                throw new BackupArchiveException('SQLite PRAGMA query failed.');
            }

            $pageCount = (int) $pageCountStatement->fetchColumn();
            $pageSize = (int) $pageSizeStatement->fetchColumn();
        } catch (Throwable $exception) {
            throw new BackupArchiveException('The SQLite database size could not be estimated.', previous: $exception);
        }

        if ($pageCount < 1 || $pageSize < 1) {
            throw new BackupArchiveException('The SQLite database size could not be estimated.');
        }

        return $pageCount * $pageSize;
    }

    private function assertFreeSpace(string $destination, int $requiredBytes): void
    {
        $availableBytes = @disk_free_space($destination);

        if ($availableBytes === false) {
            throw new BackupArchiveException('Available backup disk space could not be determined.');
        }

        if ($availableBytes < $requiredBytes) {
            throw new BackupArchiveException('There is not enough free disk space to create the backup safely.');
        }
    }

    private function createConsistentSnapshot(string $snapshotPath): void
    {
        if (file_exists($snapshotPath)) {
            throw new BackupArchiveException('The temporary SQLite snapshot path is already in use.');
        }

        try {
            $pdo = DB::connection()->getPdo();
            $result = $pdo->exec('VACUUM INTO '.$pdo->quote($snapshotPath));
        } catch (Throwable $exception) {
            throw new BackupArchiveException('The SQLite database snapshot could not be created.', previous: $exception);
        }

        if ($result === false || ! is_file($snapshotPath)) {
            throw new BackupArchiveException('The SQLite database snapshot could not be created.');
        }

        $handle = @fopen($snapshotPath, 'rb');
        $header = is_resource($handle) ? fread($handle, 16) : false;

        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\0") {
            throw new BackupArchiveException('The SQLite database snapshot is invalid.');
        }
    }

    /**
     * @param  list<array{path: string, entry: string, size: int, component: 'private_storage'|'public_storage'}>  $assets
     * @return list<array{path: string, entry: string, size: int, sha256: string, component: 'database'|'private_storage'|'public_storage'}>
     */
    private function checksumPayloads(string $snapshotPath, array $assets): array
    {
        $payloads = [[
            'path' => $snapshotPath,
            'entry' => 'database.sqlite3',
            'size' => (int) filesize($snapshotPath),
            'component' => 'database',
        ], ...$assets];

        foreach ($payloads as &$payload) {
            clearstatcache(true, $payload['path']);
            $currentSize = filesize($payload['path']);
            $checksum = hash_file('sha256', $payload['path']);

            if (! is_int($currentSize)
                || $currentSize !== $payload['size']
                || ! is_string($checksum)) {
                throw new BackupArchiveException('A backup payload changed or became unreadable during creation.');
            }

            $payload['sha256'] = $checksum;
        }
        unset($payload);

        return $payloads;
    }

    /**
     * @param  list<array{path: string, entry: string, size: int, sha256: string, component: 'database'|'private_storage'|'public_storage'}>  $payloads
     * @return array<string, mixed>
     */
    private function manifest(
        string $backupId,
        string $installationId,
        string $snapshotPath,
        array $payloads,
    ): array {
        $components = [
            'database' => ['name' => 'database', 'path' => 'database.sqlite3', 'file_count' => 0, 'size' => 0],
            'private_storage' => ['name' => 'private_storage', 'path' => 'storage/private', 'file_count' => 0, 'size' => 0],
            'public_storage' => ['name' => 'public_storage', 'path' => 'storage/public', 'file_count' => 0, 'size' => 0],
        ];

        foreach ($payloads as $payload) {
            $components[$payload['component']]['file_count']++;
            $components[$payload['component']]['size'] += $payload['size'];
        }

        $migrations = $this->snapshotMigrations($snapshotPath);
        $latestMigration = $migrations === [] ? null : max($migrations);

        return [
            'format' => self::FORMAT_IDENTIFIER,
            'format_version' => self::FORMAT_VERSION,
            'backup_id' => $backupId,
            'schema_version' => self::DATABASE_SCHEMA_VERSION,
            'application_version' => (string) config('medismart.version', 'unknown'),
            'created_at' => now()->toIso8601String(),
            'database_driver' => 'sqlite',
            'installation_id' => $installationId,
            'migration_count' => count($migrations),
            'latest_migration' => $latestMigration,
            'migration_set_sha256' => hash('sha256', implode("\n", $migrations)),
            'components' => array_values($components),
            'consistency' => [
                'database' => 'sqlite-vacuum-into',
                'assets' => 'post-snapshot-inventory-and-verification',
                'writers_quiesced' => false,
            ],
            'integrity' => [
                'profile' => 'sha256-v1',
                'authenticated' => false,
                'purpose' => 'corruption-detection',
            ],
            'portability' => [
                'profile' => 'installation-snapshot-v1',
                'machine_bound_state' => 'included',
                'secrets' => 'source-app-key-bound',
            ],
            'encryption' => [
                'enabled' => false,
                'algorithm' => null,
            ],
        ];
    }

    /**
     * @param  list<array{path: string, entry: string, size: int, component: 'private_storage'|'public_storage'}>  $assets
     */
    private function assertSnapshotReferencesAreIncluded(string $snapshotPath, array $assets): void
    {
        $availableEntries = [];

        foreach ($assets as $asset) {
            $availableEntries[$asset['entry']] = true;
        }

        $pdo = $this->snapshotConnection($snapshotPath);
        $references = [
            ['table' => 'documents', 'column' => 'file_path', 'prefix' => 'storage/private'],
            ['table' => 'medical_models', 'column' => 'file_path', 'prefix' => 'storage/private'],
            ['table' => 'cabinet_settings', 'column' => 'logo_path', 'prefix' => 'storage/public'],
            ['table' => 'doctor_profiles', 'column' => 'logo_path', 'prefix' => 'storage/public'],
        ];

        foreach ($references as $reference) {
            if (! $this->snapshotHasColumn($pdo, $reference['table'], $reference['column'])) {
                continue;
            }

            $statement = $pdo->query(
                "SELECT {$reference['column']} FROM {$reference['table']} "
                ."WHERE {$reference['column']} IS NOT NULL AND {$reference['column']} != ''",
            );

            if ($statement === false) {
                throw new BackupArchiveException('Managed asset references could not be inspected in the snapshot.');
            }

            while (($path = $statement->fetchColumn()) !== false) {
                if (! is_string($path)) {
                    throw new BackupArchiveException('The snapshot contains an invalid managed asset reference.');
                }

                $entry = $reference['prefix'].'/'.ltrim(str_replace('\\', '/', $path), '/');
                BackupArchivePath::assertSafe($entry);

                if (! isset($availableEntries[$entry])) {
                    throw new BackupArchiveException('A database-referenced managed asset is missing from storage.');
                }
            }
        }
    }

    /** @return list<string> */
    private function snapshotMigrations(string $snapshotPath): array
    {
        $pdo = $this->snapshotConnection($snapshotPath);
        $statement = $pdo->query('SELECT migration FROM migrations ORDER BY migration');

        if ($statement === false) {
            throw new BackupArchiveException('Snapshot migration metadata could not be read.');
        }

        $migrations = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map(
            static fn (mixed $migration): string => (string) $migration,
            $migrations,
        ));
    }

    private function snapshotConnection(string $snapshotPath): \PDO
    {
        try {
            return new \PDO('sqlite:'.$snapshotPath, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $exception) {
            throw new BackupArchiveException('The SQLite snapshot could not be inspected.', previous: $exception);
        }
    }

    private function snapshotHasColumn(\PDO $pdo, string $table, string $column): bool
    {
        $tableStatement = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
        );

        if (! $tableStatement->execute([$table]) || $tableStatement->fetchColumn() === false) {
            return false;
        }

        $columns = $pdo->query("PRAGMA table_info({$table})");

        if ($columns === false) {
            return false;
        }

        while (($metadata = $columns->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (($metadata['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
        } catch (Throwable $exception) {
            throw new BackupArchiveException('Backup metadata could not be encoded.', previous: $exception);
        }
    }

    /**
     * @param  list<array{path: string, entry: string, size: int, sha256: string, component: 'database'|'private_storage'|'public_storage'}>  $payloads
     */
    private function writeArchive(
        string $temporaryPath,
        string $manifestJson,
        array $payloads,
        string $checksumsJson,
    ): void {
        $zip = new ZipArchive;
        $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::EXCL);

        if ($opened !== true) {
            throw new BackupArchiveException('The temporary backup archive could not be created.');
        }

        $closed = false;

        try {
            if (! $zip->addFromString('manifest.json', $manifestJson)) {
                throw new BackupArchiveException('The backup manifest could not be written.');
            }

            foreach ($payloads as $payload) {
                BackupArchivePath::assertSafe($payload['entry']);

                if (! $zip->addFile($payload['path'], $payload['entry'])) {
                    throw new BackupArchiveException('A backup payload could not be written.');
                }
            }

            if (! $zip->addFromString('checksums.json', $checksumsJson)) {
                throw new BackupArchiveException('The backup checksums could not be written.');
            }

            $closed = $zip->close();

            if (! $closed) {
                throw new BackupArchiveException('The temporary backup archive could not be finalized.');
            }
        } catch (Throwable $exception) {
            // close() can itself flush the incomplete archive; the outer
            // creator always removes the temporary path afterwards.
            try {
                $zip->close();
            } catch (Throwable) {
                // Preserve the original archive error.
            }

            if ($exception instanceof BackupArchiveException) {
                throw $exception;
            }

            throw new BackupArchiveException('The temporary backup archive could not be written.', previous: $exception);
        }
    }
}
