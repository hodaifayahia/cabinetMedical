<?php

namespace App\Backups;

use DateTimeImmutable;
use Illuminate\Support\Str;
use JsonException;
use ZipArchive;

final class MsBackupArchiveVerifier implements BackupArchiveVerifier
{
    public const MAXIMUM_ENTRIES = 100_000;

    public const MAXIMUM_METADATA_BYTES = 16 * 1024 * 1024;

    public const MAXIMUM_ARCHIVE_BYTES = 512 * 1024 * 1024 * 1024;

    public const MAXIMUM_ENTRY_BYTES = 256 * 1024 * 1024 * 1024;

    public const MAXIMUM_UNCOMPRESSED_BYTES = 512 * 1024 * 1024 * 1024;

    private const MAXIMUM_COMPRESSION_RATIO = 250;

    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     archive_size: int,
     *     archive_sha256: string,
     *     entry_count: int
     * }
     */
    public function verify(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupArchiveException('The ZIP extension is required to verify Drclick backups.');
        }

        if (! is_file($path) || ! is_readable($path) || Str::lower(pathinfo($path, PATHINFO_EXTENSION)) !== 'msbackup') {
            throw new BackupArchiveException('The selected file is not a readable Drclick backup.');
        }

        $archiveSize = filesize($path);
        $archiveChecksum = hash_file('sha256', $path);

        if (! is_int($archiveSize)
            || $archiveSize > self::MAXIMUM_ARCHIVE_BYTES
            || ! is_string($archiveChecksum)) {
            throw new BackupArchiveException('The backup archive could not be inspected.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw new BackupArchiveException('The selected file is not a valid ZIP archive.');
        }

        try {
            $archiveComment = $zip->getArchiveComment(ZipArchive::FL_UNCHANGED);

            if ($archiveComment !== '') {
                throw new BackupArchiveException('Backup archive comments are not supported.');
            }

            $entries = $this->validatedEntries($zip);
            $manifest = $this->decodeMetadataEntry($zip, $entries, 'manifest.json');
            $checksums = $this->decodeMetadataEntry($zip, $entries, 'checksums.json');

            $this->validateManifest($manifest);
            $files = $this->validateChecksumDocument($checksums, $entries);
            $this->verifyPayloads($zip, $entries, $files);
            $this->validateComponents($manifest, $files);
        } finally {
            $zip->close();
        }

        return [
            'manifest' => $manifest,
            'archive_size' => $archiveSize,
            'archive_sha256' => $archiveChecksum,
            'entry_count' => count($entries),
        ];
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function validatedEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 3 || $zip->numFiles > self::MAXIMUM_ENTRIES) {
            throw new BackupArchiveException('The backup archive has an invalid number of entries.');
        }

        $entries = [];
        $portableNames = [];
        $uncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED);
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);

            if (! is_string($name) || ! is_array($stat)) {
                throw new BackupArchiveException('The backup archive directory is invalid.');
            }

            BackupArchivePath::assertSafe($name);

            $entryComment = $zip->getCommentIndex($index, ZipArchive::FL_UNCHANGED);

            if ($entryComment !== '') {
                throw new BackupArchiveException('Backup entry comments are not supported.');
            }

            if ($stat['size'] < 0
                || $stat['size'] > self::MAXIMUM_ENTRY_BYTES
                || $stat['comp_size'] < 0
                || ($stat['size'] > 0 && $stat['comp_size'] === 0)
                || ($stat['size'] > 1024 * 1024
                    && $stat['size'] > $stat['comp_size'] * self::MAXIMUM_COMPRESSION_RATIO)
                || $uncompressedBytes > self::MAXIMUM_UNCOMPRESSED_BYTES - $stat['size']) {
                throw new BackupArchiveException('The backup archive exceeds safe size limits.');
            }

            $uncompressedBytes += $stat['size'];

            $portableName = BackupArchivePath::portableKey($name);

            if (isset($portableNames[$portableName])) {
                throw new BackupArchiveException('The backup contains duplicate entry names.');
            }

            $portableNames[$portableName] = true;

            if (defined(ZipArchive::class.'::EM_NONE')
                && $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new BackupArchiveException('This backup uses an unsupported entry encryption method.');
            }

            if (! in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new BackupArchiveException('This backup uses an unsupported compression method.');
            }

            $operatingSystem = 0;
            $attributes = 0;

            if ($zip->getExternalAttributesIndex(
                $index,
                $operatingSystem,
                $attributes,
                ZipArchive::FL_UNCHANGED,
            )) {
                $fileType = ($attributes >> 16) & 0xF000;

                if ($fileType !== 0 && $fileType !== 0x8000) {
                    throw new BackupArchiveException('The backup contains a non-regular filesystem entry.');
                }
            }

            $entries[$name] = $stat;
        }

        foreach (['manifest.json', 'database.sqlite3', 'checksums.json'] as $required) {
            if (! isset($entries[$required])) {
                throw new BackupArchiveException('The backup archive is missing a required entry.');
            }
        }

        foreach (array_keys($entries) as $entry) {
            if ($entry !== 'manifest.json'
                && $entry !== 'database.sqlite3'
                && $entry !== 'checksums.json'
                && ! str_starts_with($entry, 'storage/private/')
                && ! str_starts_with($entry, 'storage/public/')) {
                throw new BackupArchiveException('The backup contains an unexpected entry.');
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  array<string, array<string, int|string>>  $entries
     * @return array<string, mixed>
     */
    private function decodeMetadataEntry(ZipArchive $zip, array $entries, string $name): array
    {
        $size = $entries[$name]['size'] ?? null;

        if (! is_int($size) || $size < 2 || $size > self::MAXIMUM_METADATA_BYTES) {
            throw new BackupArchiveException('The backup metadata has an invalid size.');
        }

        $json = $zip->getFromName($name, $size, ZipArchive::FL_UNCHANGED);

        if (! is_string($json) || strlen($json) !== $size) {
            throw new BackupArchiveException('The backup metadata could not be read.');
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupArchiveException('The backup metadata is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new BackupArchiveException('The backup metadata has an invalid structure.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== MsBackupArchiveCreator::FORMAT_IDENTIFIER
            || ($manifest['format_version'] ?? null) !== MsBackupArchiveCreator::FORMAT_VERSION
            || ($manifest['schema_version'] ?? null) !== MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION
            || ($manifest['database_driver'] ?? null) !== 'sqlite'
            || ! is_string($manifest['application_version'] ?? null)
            || trim($manifest['application_version']) === ''
            || ! is_string($manifest['installation_id'] ?? null)
            || ! Str::isUuid($manifest['installation_id'])
            || ! is_string($manifest['backup_id'] ?? null)
            || ! Str::isUuid($manifest['backup_id'])
            || ! is_int($manifest['migration_count'] ?? null)
            || $manifest['migration_count'] < 0
            || ! array_key_exists('latest_migration', $manifest)
            || (! is_null($manifest['latest_migration']) && ! is_string($manifest['latest_migration']))
            || ! is_string($manifest['migration_set_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['migration_set_sha256']) !== 1
            || ! is_array($manifest['components'] ?? null)
            || ($manifest['consistency'] ?? null) !== [
                'database' => 'sqlite-vacuum-into',
                'assets' => 'post-snapshot-inventory-and-verification',
                'writers_quiesced' => false,
            ]
            || ($manifest['integrity'] ?? null) !== [
                'profile' => 'sha256-v1',
                'authenticated' => false,
                'purpose' => 'corruption-detection',
            ]
            || ($manifest['portability'] ?? null) !== [
                'profile' => 'installation-snapshot-v1',
                'machine_bound_state' => 'included',
                'secrets' => 'source-app-key-bound',
            ]
            || ! is_array($manifest['encryption'] ?? null)
            || ($manifest['encryption']['enabled'] ?? null) !== false
            || array_key_exists('algorithm', $manifest['encryption']) && $manifest['encryption']['algorithm'] !== null) {
            throw new BackupArchiveException('The backup manifest is incompatible or malformed.');
        }

        $createdAt = $manifest['created_at'] ?? null;

        if (! is_string($createdAt)) {
            throw new BackupArchiveException('The backup manifest has an invalid creation time.');
        }

        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt);

        if ($date === false || $date->format(DATE_ATOM) !== $createdAt) {
            throw new BackupArchiveException('The backup manifest has an invalid creation time.');
        }
    }

    /**
     * @param  array<string, mixed>  $checksums
     * @param  array<string, array<string, int|string>>  $entries
     * @return array<string, array{sha256: string, size: int}>
     */
    private function validateChecksumDocument(array $checksums, array $entries): array
    {
        $checksumEntries = $checksums['entries'] ?? null;

        if (($checksums['format'] ?? null) !== 'medismart-checksums'
            || ($checksums['version'] ?? null) !== 1
            || ($checksums['algorithm'] ?? null) !== 'sha256'
            || ! is_array($checksumEntries)
            || ! array_is_list($checksumEntries)) {
            throw new BackupArchiveException('The backup checksum document is malformed.');
        }

        $files = [];
        $portableNames = [];

        foreach ($checksumEntries as $metadata) {
            if (! is_array($metadata)
                || array_is_list($metadata)
                || ! is_string($metadata['path'] ?? null)
                || ! is_string($metadata['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $metadata['sha256']) !== 1
                || ! is_int($metadata['size'] ?? null)
                || $metadata['size'] < 0) {
                throw new BackupArchiveException('The backup contains invalid checksum metadata.');
            }

            $entry = $metadata['path'];
            BackupArchivePath::assertSafe($entry);
            $portableName = BackupArchivePath::portableKey($entry);

            if (isset($portableNames[$portableName])) {
                throw new BackupArchiveException('The backup checksum document contains duplicate paths.');
            }

            $portableNames[$portableName] = true;
            $files[$entry] = [
                'sha256' => $metadata['sha256'],
                'size' => $metadata['size'],
            ];
        }

        $expectedEntries = array_keys($entries);
        $expectedEntries = array_values(array_filter(
            $expectedEntries,
            static fn (string $entry): bool => $entry !== 'checksums.json',
        ));
        $listedEntries = array_keys($files);
        sort($expectedEntries, SORT_STRING);
        sort($listedEntries, SORT_STRING);

        if ($expectedEntries !== $listedEntries) {
            throw new BackupArchiveException('The backup checksum list does not match the archive contents.');
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, array<string, int|string>>  $entries
     * @param  array<string, array{sha256: string, size: int}>  $files
     */
    private function verifyPayloads(ZipArchive $zip, array $entries, array $files): void
    {
        foreach ($files as $entry => $expected) {
            if (($entries[$entry]['size'] ?? null) !== $expected['size']) {
                throw new BackupArchiveException('A backup entry size does not match its checksum metadata.');
            }

            $stream = $zip->getStream($entry);

            if (! is_resource($stream)) {
                throw new BackupArchiveException('A backup entry could not be read.');
            }

            $hash = hash_init('sha256');
            $bytesRead = 0;
            $databaseHeader = '';

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        throw new BackupArchiveException('A backup entry could not be read.');
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    if ($bytesRead > $expected['size'] - strlen($chunk)) {
                        throw new BackupArchiveException('A backup entry exceeded its declared size.');
                    }

                    if ($entry === 'database.sqlite3' && strlen($databaseHeader) < 16) {
                        $databaseHeader .= substr($chunk, 0, 16 - strlen($databaseHeader));
                    }

                    $bytesRead += strlen($chunk);
                    hash_update($hash, $chunk);
                }
            } finally {
                fclose($stream);
            }

            if ($bytesRead !== $expected['size']
                || ! hash_equals($expected['sha256'], hash_final($hash))) {
                throw new BackupArchiveException('A backup entry failed checksum verification.');
            }

            if ($entry === 'database.sqlite3' && $databaseHeader !== "SQLite format 3\0") {
                throw new BackupArchiveException('The backup database snapshot is not valid SQLite.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array{sha256: string, size: int}>  $files
     */
    private function validateComponents(array $manifest, array $files): void
    {
        $actual = [
            'database' => ['name' => 'database', 'path' => 'database.sqlite3', 'file_count' => 1, 'size' => $files['database.sqlite3']['size']],
            'private_storage' => ['name' => 'private_storage', 'path' => 'storage/private', 'file_count' => 0, 'size' => 0],
            'public_storage' => ['name' => 'public_storage', 'path' => 'storage/public', 'file_count' => 0, 'size' => 0],
        ];

        foreach ($files as $entry => $metadata) {
            $component = match (true) {
                str_starts_with($entry, 'storage/private/') => 'private_storage',
                str_starts_with($entry, 'storage/public/') => 'public_storage',
                default => null,
            };

            if ($component !== null) {
                $actual[$component]['file_count']++;
                $actual[$component]['size'] += $metadata['size'];
            }
        }

        $declared = [];

        foreach ($manifest['components'] as $component) {
            if (! is_array($component)
                || array_is_list($component)
                || ! is_string($component['name'] ?? null)
                || isset($declared[$component['name']])) {
                throw new BackupArchiveException('The backup component manifest is malformed.');
            }

            $declared[$component['name']] = $component;
        }

        if ($declared !== $actual) {
            throw new BackupArchiveException('The backup component manifest does not match its contents.');
        }
    }
}
