<?php

namespace App\Backups;

use JsonException;
use Throwable;
use ZipArchive;

final class StagedMsBackupExtractor
{
    private const CHUNK_BYTES = 1024 * 1024;

    public function __construct(
        private readonly MsBackupArchiveVerifier $verifier,
    ) {}

    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     file_count: int,
     *     bytes: int,
     *     archive_sha256: string,
     *     inventory: list<array{path: string, size: int, sha256: string}>
     * }
     */
    public function extract(string $archivePath, string $stagingRoot): array
    {
        $archive = realpath($archivePath);
        $root = realpath($stagingRoot);

        if (! is_string($archive) || ! is_file($archive) || is_link($archivePath)
            || ! is_string($root) || ! is_dir($root) || is_link($stagingRoot)) {
            throw new BackupArchiveException('The restore staging input is unavailable.');
        }

        $archiveHandle = @fopen($archive, 'rb');

        if (! is_resource($archiveHandle) || ! flock($archiveHandle, LOCK_SH)) {
            if (is_resource($archiveHandle)) {
                fclose($archiveHandle);
            }

            throw new BackupArchiveException('The verified restore archive could not be locked for extraction.');
        }

        $zip = new ZipArchive;

        try {
            $initialStat = fstat($archiveHandle);

            if ($initialStat === false) {
                throw new BackupArchiveException('The verified restore archive could not be inspected.');
            }

            $verified = $this->verifier->verify($archive);
            $this->assertArchiveIdentity($archive, $initialStat);
            $opened = $zip->open($archive, ZipArchive::CHECKCONS);

            if ($opened !== true) {
                throw new BackupArchiveException('The verified restore archive could not be opened for extraction.');
            }

            try {
                $checksums = $this->checksumEntries($zip);
                $this->assertFreeSpace($root, $checksums);
                $this->createManagedRoots($root);
                $fileCount = 0;
                $bytes = 0;
                $inventory = [];

                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED);

                    if (! is_string($entry)) {
                        throw new BackupArchiveException('The verified restore archive directory is malformed.');
                    }

                    if ($entry === 'manifest.json' || $entry === 'checksums.json') {
                        continue;
                    }

                    $target = $this->stagedTarget($root, $entry);
                    $expected = $checksums[$entry] ?? null;

                    if (! is_array($expected)) {
                        throw new BackupArchiveException('A staged restore payload has no verified checksum.');
                    }

                    $this->extractEntry($zip, $entry, $target, $expected['size'], $expected['sha256'], $root);
                    $fileCount++;
                    $bytes += $expected['size'];
                    $inventory[] = [
                        'path' => ltrim(str_replace('\\', '/', substr($target, strlen($root))), '/'),
                        'size' => $expected['size'],
                        'sha256' => $expected['sha256'],
                    ];
                }

                if ($fileCount < 1 || ! is_file($root.DIRECTORY_SEPARATOR.'database.sqlite3')) {
                    throw new BackupArchiveException('The staged restore payload is incomplete.');
                }
            } finally {
                $zip->close();
            }

            $finalStat = fstat($archiveHandle);

            if ($finalStat === false
                || $initialStat['size'] !== $finalStat['size']
                || $initialStat['mtime'] !== $finalStat['mtime']
                || $initialStat['dev'] !== $finalStat['dev']
                || $initialStat['ino'] !== $finalStat['ino']) {
                throw new BackupArchiveException('The verified restore archive changed during extraction.');
            }

            return [
                'manifest' => $verified['manifest'],
                'file_count' => $fileCount,
                'bytes' => $bytes,
                'archive_sha256' => $verified['archive_sha256'],
                'inventory' => $inventory,
            ];
        } finally {
            try {
                $zip->close();
            } catch (Throwable) {
                // Preserve any extraction exception.
            }

            flock($archiveHandle, LOCK_UN);
            fclose($archiveHandle);
        }
    }

    /** @return array<string, array{size: int, sha256: string}> */
    private function checksumEntries(ZipArchive $zip): array
    {
        $json = $zip->getFromName('checksums.json', MsBackupArchiveVerifier::MAXIMUM_METADATA_BYTES, ZipArchive::FL_UNCHANGED);

        try {
            $document = is_string($json)
                ? json_decode($json, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            throw new BackupArchiveException('The verified restore checksum metadata could not be read.');
        }

        $entries = is_array($document) ? ($document['entries'] ?? null) : null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new BackupArchiveException('The verified restore checksum metadata is malformed.');
        }

        $checksums = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['path'] ?? null)
                || ! is_int($entry['size'] ?? null)
                || ! is_string($entry['sha256'] ?? null)) {
                throw new BackupArchiveException('The verified restore checksum metadata is malformed.');
            }

            $checksums[$entry['path']] = [
                'size' => $entry['size'],
                'sha256' => $entry['sha256'],
            ];
        }

        return $checksums;
    }

    private function stagedTarget(string $root, string $entry): string
    {
        BackupArchivePath::assertSafe($entry);

        if ($entry === 'database.sqlite3') {
            return $root.DIRECTORY_SEPARATOR.'database.sqlite3';
        }

        foreach ([
            'storage/private/clinical-documents/' => ['private', 'clinical-documents'],
            'storage/private/patient-documents/' => ['private', 'patient-documents'],
            'storage/private/medical-models/' => ['private', 'medical-models'],
            'storage/public/cabinet/' => ['public', 'cabinet'],
        ] as $prefix => $segments) {
            if (! str_starts_with($entry, $prefix)) {
                continue;
            }

            $relative = substr($entry, strlen($prefix));

            if ($relative === '') {
                break;
            }

            return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments)
                .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }

        throw new BackupArchiveException('The backup contains data outside the four managed restore roots.');
    }

    private function extractEntry(
        ZipArchive $zip,
        string $entry,
        string $target,
        int $expectedSize,
        string $expectedSha256,
        string $root,
    ): void {
        $this->ensureDirectory(dirname($target), $root);

        if (file_exists($target) || is_link($target)) {
            throw new BackupArchiveException('The staged restore contains a colliding payload path.');
        }

        $input = $zip->getStream($entry);
        $output = @fopen($target, 'xb');

        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            throw new BackupArchiveException('A verified restore payload could not be staged.');
        }

        try {
            $this->secureFile($target);
            $hash = hash_init('sha256');
            $bytes = 0;

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new BackupArchiveException('A verified restore payload could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                if ($bytes > $expectedSize - strlen($chunk)) {
                    throw new BackupArchiveException('A restore payload exceeded its verified size.');
                }

                $this->writeAll($output, $chunk);
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }

            if ($bytes !== $expectedSize || ! hash_equals($expectedSha256, hash_final($hash))
                || ! fflush($output) || (function_exists('fsync') && ! fsync($output))) {
                throw new BackupArchiveException('A staged restore payload failed checksum verification.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function createManagedRoots(string $root): void
    {
        foreach ([
            $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'clinical-documents',
            $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'patient-documents',
            $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical-models',
            $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'cabinet',
        ] as $directory) {
            $this->ensureDirectory($directory, $root);
        }
    }

    /** @param array<string, array{size: int, sha256: string}> $checksums */
    private function assertFreeSpace(string $root, array $checksums): void
    {
        $required = 16 * 1024 * 1024;

        foreach ($checksums as $entry => $metadata) {
            if ($entry !== 'manifest.json') {
                $required += $metadata['size'];
            }
        }

        $available = @disk_free_space($root);

        if ($available === false || $available < $required) {
            throw new BackupArchiveException('There is not enough free disk space to stage the restore safely.');
        }
    }

    private function ensureDirectory(string $directory, string $root): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && (! @mkdir($directory, 0700, true) && ! is_dir($directory)))) {
            throw new BackupArchiveException('A restore staging directory could not be created safely.');
        }

        $resolved = realpath($directory);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedDirectory = is_string($resolved) ? str_replace('\\', '/', $resolved) : '';

        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedRoot = strtolower($normalizedRoot);
            $normalizedDirectory = strtolower($normalizedDirectory);
        }

        if ($normalizedDirectory !== $normalizedRoot
            && ! str_starts_with($normalizedDirectory, $normalizedRoot.'/')) {
            throw new BackupArchiveException('A restore staging directory escaped its workspace.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new BackupArchiveException('A restore staging directory could not be secured.');
        }
    }

    /** @param array<int|string, int> $expected */
    private function assertArchiveIdentity(string $path, array $expected): void
    {
        $actual = @stat($path);

        if (! is_array($actual)
            || $actual['size'] !== $expected['size']
            || $actual['mtime'] !== $expected['mtime']
            || $actual['dev'] !== $expected['dev']
            || $actual['ino'] !== $expected['ino']) {
            throw new BackupArchiveException('The verified restore archive changed before extraction.');
        }
    }

    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));

            if (! is_int($written) || $written < 1) {
                throw new BackupArchiveException('A verified restore payload could not be staged.');
            }

            $offset += $written;
        }
    }

    private function secureFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
            throw new BackupArchiveException('A staged restore payload could not be secured.');
        }
    }
}
