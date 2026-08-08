<?php

namespace App\Backups;

use HashContext;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class EncryptedMsBackupArchive
{
    /** 16-byte discriminator; the last byte denotes encrypted format v2. */
    private const MAGIC = "MEDISMART-MSBAK\x02";

    private const MAXIMUM_ENVELOPE_BYTES = 64 * 1024;

    private const MAXIMUM_ENCRYPTED_ARCHIVE_BYTES = 513 * 1024 * 1024 * 1024;

    private const MINIMUM_FREE_SPACE_OVERHEAD = 16 * 1024 * 1024;

    public function __construct(
        private readonly MsBackupArchiveVerifier $innerVerifier,
    ) {}

    /**
     * Authenticates and encrypts an existing verified v1 .msbackup. The source
     * remains untouched and the encrypted v2 envelope is atomically published.
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     envelope: array<string, mixed>,
     *     manifest: array<string, mixed>
     * }
     */
    public function encrypt(
        string $sourcePath,
        string $destinationPath,
        #[SensitiveParameter] string $passphrase,
        ?MsBackupEncryptionParameters $parameters = null,
    ): array {
        $this->assertCryptographyAvailable();
        $this->assertPassphrase($passphrase);
        $parameters ??= MsBackupEncryptionParameters::production();

        $source = $this->readableArchivePath($sourcePath);
        $destination = $this->newDestinationPath($destinationPath);

        if ($this->portableFilesystemPath($source) === $this->portableFilesystemPath($destination)) {
            throw new BackupArchiveException('The encrypted backup destination must differ from its source archive.');
        }

        $sourceHandle = @fopen($source, 'rb');

        if (! is_resource($sourceHandle) || ! flock($sourceHandle, LOCK_SH)) {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }

            throw new BackupArchiveException('The source backup could not be locked for encryption.');
        }

        $temporaryPath = $this->temporaryPath(dirname($destination), basename($destination));
        $outputHandle = null;
        $streamState = null;

        try {
            $sourceStat = $this->validStreamStat($sourceHandle, MsBackupArchiveVerifier::MAXIMUM_ARCHIVE_BYTES);
            $verified = $this->innerVerifier->verify($source);
            $this->assertPathStillReferencesStream($source, $sourceStat);
            $this->rewindStream($sourceHandle);

            $preflight = $this->hashStream($sourceHandle);

            if ($preflight['size'] !== $verified['archive_size']
                || ! hash_equals($verified['archive_sha256'], $preflight['sha256'])) {
                throw new BackupArchiveException('The source backup changed while encryption was prepared.');
            }

            $this->rewindStream($sourceHandle);
            $this->assertFreeSpace(dirname($destination), $this->encryptedSizeEstimate(
                $preflight['size'],
                $parameters->chunkBytes,
            ));

            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->deriveKey($passphrase, $salt, $parameters);

            try {
                [$streamState, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            } catch (Throwable) {
                throw new BackupArchiveException('The encrypted backup operation could not be completed.');
            } finally {
                sodium_memzero($key);
            }

            $envelope = new EncryptedMsBackupEnvelope(
                createdAt: now()->toIso8601String(),
                plaintextSize: $preflight['size'],
                plaintextSha256: $preflight['sha256'],
                salt: $salt,
                operationsLimit: $parameters->operationsLimit,
                memoryLimitBytes: $parameters->memoryLimitBytes,
                streamHeader: $streamHeader,
                chunkBytes: $parameters->chunkBytes,
            );
            $envelopeJson = $envelope->toJson();

            if (strlen($envelopeJson) > self::MAXIMUM_ENVELOPE_BYTES) {
                throw new BackupArchiveException('The encrypted backup envelope exceeds its safety limit.');
            }

            $outputHandle = @fopen($temporaryPath, 'xb');

            if (! is_resource($outputHandle)) {
                throw new BackupArchiveException('The temporary encrypted backup could not be created.');
            }

            $this->restrictTemporaryFile($temporaryPath);

            $archiveHash = hash_init('sha256');
            $archiveSize = 0;
            $this->writeAndHash($outputHandle, self::MAGIC, $archiveHash, $archiveSize);
            $this->writeAndHash($outputHandle, pack('N', strlen($envelopeJson)), $archiveHash, $archiveSize);
            $this->writeAndHash($outputHandle, $envelopeJson, $archiveHash, $archiveSize);

            $plaintextHash = hash_init('sha256');
            $plaintextSize = 0;

            while (! feof($sourceHandle)) {
                $chunk = fread($sourceHandle, max(1, $parameters->chunkBytes));

                if ($chunk === false) {
                    throw new BackupArchiveException('The source backup could not be read during encryption.');
                }

                if ($chunk === '') {
                    continue;
                }

                $plaintextSize += strlen($chunk);
                hash_update($plaintextHash, $chunk);
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $streamState,
                    $chunk,
                    $envelopeJson,
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                $this->writeFrame($outputHandle, $ciphertext, $archiveHash, $archiveSize);
            }

            $finalCiphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $streamState,
                '',
                $envelopeJson,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            $this->writeFrame($outputHandle, $finalCiphertext, $archiveHash, $archiveSize);
            sodium_memzero($streamState);
            $streamState = null;

            if ($plaintextSize !== $preflight['size']
                || ! hash_equals($preflight['sha256'], hash_final($plaintextHash))) {
                throw new BackupArchiveException('The source backup changed during encryption.');
            }

            $this->assertStreamUnchanged($sourceHandle, $sourceStat);
            $this->flushFile($outputHandle);
            fclose($outputHandle);
            $outputHandle = null;

            $this->publishWithoutOverwrite($temporaryPath, $destination, 'encrypted');

            @chmod($destination, 0640);

            return [
                'path' => $destination,
                'filename' => basename($destination),
                'size' => $archiveSize,
                'sha256' => hash_final($archiveHash),
                'envelope' => $envelope->toArray(),
                'manifest' => $verified['manifest'],
            ];
        } finally {
            if (is_string($streamState)) {
                sodium_memzero($streamState);
            }

            if (is_resource($outputHandle)) {
                fclose($outputHandle);
            }

            flock($sourceHandle, LOCK_UN);
            fclose($sourceHandle);

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Fully authenticates, decrypts, and verifies the inner v1 archive without
     * retaining plaintext after the call.
     *
     * @return array{
     *     envelope: array<string, mixed>,
     *     manifest: array<string, mixed>,
     *     archive_size: int,
     *     archive_sha256: string,
     *     plaintext_size: int,
     *     plaintext_sha256: string,
     *     entry_count: int
     * }
     */
    public function verify(
        string $encryptedPath,
        #[SensitiveParameter] string $passphrase,
        ?string $workingDirectory = null,
    ): array {
        $directory = $workingDirectory === null
            ? $this->privateWorkingDirectory()
            : $this->writableDirectory($workingDirectory);
        $temporaryPath = $this->temporaryPath($directory, 'verified-inner.msbackup');

        try {
            return $this->decryptAndVerifyToTemporary($encryptedPath, $temporaryPath, $passphrase);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Authenticates and verifies before atomically publishing the decrypted v1
     * archive. Existing destinations are never overwritten.
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     envelope: array<string, mixed>,
     *     manifest: array<string, mixed>,
     *     archive_size: int,
     *     archive_sha256: string,
     *     plaintext_size: int,
     *     plaintext_sha256: string,
     *     entry_count: int
     * }
     */
    public function decrypt(
        string $encryptedPath,
        string $destinationPath,
        #[SensitiveParameter] string $passphrase,
        bool $requireSourceExtension = true,
    ): array {
        $destination = $this->newDestinationPath($destinationPath);
        $source = $this->readableArchivePath($encryptedPath, $requireSourceExtension);

        if ($this->portableFilesystemPath($source) === $this->portableFilesystemPath($destination)) {
            throw new BackupArchiveException('The decrypted backup destination must differ from its encrypted source.');
        }

        $temporaryPath = $this->temporaryPath(dirname($destination), basename($destination));

        try {
            $verified = $this->decryptAndVerifyToTemporary(
                $source,
                $temporaryPath,
                $passphrase,
                $requireSourceExtension,
            );

            $this->publishWithoutOverwrite($temporaryPath, $destination, 'decrypted');

            @chmod($destination, 0640);

            return [
                'path' => $destination,
                'filename' => basename($destination),
                ...$verified,
            ];
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @return array{
     *     envelope: array<string, mixed>,
     *     manifest: array<string, mixed>,
     *     archive_size: int,
     *     archive_sha256: string,
     *     plaintext_size: int,
     *     plaintext_sha256: string,
     *     entry_count: int
     * }
     */
    private function decryptAndVerifyToTemporary(
        string $encryptedPath,
        string $temporaryPath,
        #[SensitiveParameter] string $passphrase,
        bool $requireSourceExtension = true,
    ): array {
        $this->assertCryptographyAvailable();
        $this->assertPassphrase($passphrase);
        $source = $this->readableArchivePath($encryptedPath, $requireSourceExtension);
        $decrypted = $this->decryptToTemporary($source, $temporaryPath, $passphrase);
        $verified = $this->innerVerifier->verify($temporaryPath);

        if ($verified['archive_size'] !== $decrypted['plaintext_size']
            || ! hash_equals($verified['archive_sha256'], $decrypted['plaintext_sha256'])) {
            throw $this->authenticationFailure();
        }

        return [
            'envelope' => $decrypted['envelope']->toArray(),
            'manifest' => $verified['manifest'],
            'archive_size' => $decrypted['archive_size'],
            'archive_sha256' => $decrypted['archive_sha256'],
            'plaintext_size' => $decrypted['plaintext_size'],
            'plaintext_sha256' => $decrypted['plaintext_sha256'],
            'entry_count' => $verified['entry_count'],
        ];
    }

    /**
     * @return array{
     *     envelope: EncryptedMsBackupEnvelope,
     *     archive_size: int,
     *     archive_sha256: string,
     *     plaintext_size: int,
     *     plaintext_sha256: string
     * }
     */
    private function decryptToTemporary(
        string $source,
        string $temporaryPath,
        #[SensitiveParameter] string $passphrase,
    ): array {
        $input = @fopen($source, 'rb');

        if (! is_resource($input) || ! flock($input, LOCK_SH)) {
            if (is_resource($input)) {
                fclose($input);
            }

            throw new BackupArchiveException('The encrypted backup could not be locked for verification.');
        }

        $output = null;
        $streamState = null;
        $completed = false;

        try {
            $sourceStat = $this->validStreamStat($input, self::MAXIMUM_ENCRYPTED_ARCHIVE_BYTES);
            $archiveHash = hash_init('sha256');
            $archiveSize = 0;
            $magic = $this->readAndHashExact($input, strlen(self::MAGIC), $archiveHash, $archiveSize, false);

            if ($magic !== self::MAGIC) {
                throw new BackupArchiveException('The selected file is not a supported encrypted Drclick backup.');
            }

            $lengthBytes = $this->readAndHashExact($input, 4, $archiveHash, $archiveSize, false);
            $unpacked = unpack('Nlength', $lengthBytes);
            $envelopeBytes = is_array($unpacked) ? ($unpacked['length'] ?? null) : null;

            if (! is_int($envelopeBytes) || $envelopeBytes < 2 || $envelopeBytes > self::MAXIMUM_ENVELOPE_BYTES) {
                throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
            }

            $envelopeJson = $this->readAndHashExact(
                $input,
                $envelopeBytes,
                $archiveHash,
                $archiveSize,
                false,
            );
            $envelope = EncryptedMsBackupEnvelope::fromJson($envelopeJson);
            $this->assertFreeSpace(dirname($temporaryPath), $envelope->plaintextSize + self::MINIMUM_FREE_SPACE_OVERHEAD);
            $key = $this->deriveKey(
                $passphrase,
                $envelope->salt,
                new MsBackupEncryptionParameters(
                    $envelope->operationsLimit,
                    $envelope->memoryLimitBytes,
                    $envelope->chunkBytes,
                ),
            );

            try {
                $streamState = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                    $envelope->streamHeader,
                    $key,
                );
            } catch (Throwable) {
                throw $this->authenticationFailure();
            } finally {
                sodium_memzero($key);
            }

            $output = @fopen($temporaryPath, 'xb');

            if (! is_resource($output)) {
                throw new BackupArchiveException('The temporary decrypted backup could not be created.');
            }

            $this->restrictTemporaryFile($temporaryPath);

            $plaintextHash = hash_init('sha256');
            $plaintextSize = 0;

            while (true) {
                $remaining = $envelope->plaintextSize - $plaintextSize;
                $expectedPlaintextBytes = min($envelope->chunkBytes, $remaining);
                $expectedCiphertextBytes = $expectedPlaintextBytes
                    + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                $frameLengthBytes = $this->readAndHashExact(
                    $input,
                    4,
                    $archiveHash,
                    $archiveSize,
                    true,
                );
                $frameLengthData = unpack('Nlength', $frameLengthBytes);
                $frameLength = is_array($frameLengthData) ? ($frameLengthData['length'] ?? null) : null;

                if (! is_int($frameLength) || $frameLength !== $expectedCiphertextBytes) {
                    throw $this->authenticationFailure();
                }

                $ciphertext = $this->readAndHashExact(
                    $input,
                    $frameLength,
                    $archiveHash,
                    $archiveSize,
                    true,
                );
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull(
                    $streamState,
                    $ciphertext,
                    $envelopeJson,
                );

                if ($pulled === false) {
                    throw $this->authenticationFailure();
                }

                [$plaintext, $tag] = $pulled;

                if ($remaining === 0) {
                    if ($plaintext !== ''
                        || $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        throw $this->authenticationFailure();
                    }

                    break;
                }

                if (strlen($plaintext) !== $expectedPlaintextBytes
                    || $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw $this->authenticationFailure();
                }

                $this->writeAll($output, $plaintext);
                hash_update($plaintextHash, $plaintext);
                $plaintextSize += strlen($plaintext);
            }

            $trailing = fread($input, 1);

            if ($trailing === false || $trailing !== '') {
                throw $this->authenticationFailure();
            }

            $this->assertStreamUnchanged($input, $sourceStat);
            sodium_memzero($streamState);
            $streamState = null;
            $plaintextSha256 = hash_final($plaintextHash);

            if ($plaintextSize !== $envelope->plaintextSize
                || ! hash_equals($envelope->plaintextSha256, $plaintextSha256)
                || $archiveSize !== $sourceStat['size']) {
                throw $this->authenticationFailure();
            }

            $this->flushFile($output);
            fclose($output);
            $output = null;
            @chmod($temporaryPath, 0600);
            $completed = true;

            return [
                'envelope' => $envelope,
                'archive_size' => $archiveSize,
                'archive_sha256' => hash_final($archiveHash),
                'plaintext_size' => $plaintextSize,
                'plaintext_sha256' => $plaintextSha256,
            ];
        } finally {
            if (is_string($streamState)) {
                sodium_memzero($streamState);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            flock($input, LOCK_UN);
            fclose($input);

            if (! $completed && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function assertCryptographyAvailable(): void
    {
        if (! extension_loaded('sodium')
            || ! function_exists('sodium_crypto_pwhash')
            || ! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new BackupArchiveException('The Sodium extension is required for encrypted Drclick backups.');
        }

        if (PHP_INT_SIZE < 8) {
            throw new BackupArchiveException('Encrypted Drclick backups require a 64-bit PHP runtime.');
        }
    }

    private function assertPassphrase(#[SensitiveParameter] string $passphrase): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($passphrase, 'UTF-8') : strlen($passphrase);

        if ($length < 12 || strlen($passphrase) > 1024
            || preg_match('//u', $passphrase) !== 1
            || str_contains($passphrase, "\0")) {
            throw new BackupArchiveException('The recovery passphrase must contain 12 to 1024 valid UTF-8 characters.');
        }
    }

    private function deriveKey(
        #[SensitiveParameter] string $passphrase,
        string $salt,
        MsBackupEncryptionParameters $parameters,
    ): string {
        try {
            return sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                $passphrase,
                $salt,
                $parameters->operationsLimit,
                $parameters->memoryLimitBytes,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
        } catch (Throwable) {
            throw new BackupArchiveException('The encrypted backup operation could not be completed.');
        }
    }

    private function readableArchivePath(string $path, bool $requireExtension = true): string
    {
        $resolved = realpath($path);

        if (! is_string($resolved) || ! is_file($resolved) || ! is_readable($resolved)
            || ($requireExtension
                && Str::lower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'msbackup')) {
            throw new BackupArchiveException('The selected file is not a readable Drclick backup.');
        }

        return $resolved;
    }

    private function newDestinationPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new BackupArchiveException('The backup destination is invalid.');
        }

        $filename = basename($path);
        BackupArchivePath::assertSafe($filename);

        if (Str::lower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'msbackup') {
            throw new BackupArchiveException('The backup destination must use the .msbackup extension.');
        }

        $directory = $this->writableDirectory(dirname($path));
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;

        if (file_exists($destination)) {
            throw new BackupArchiveException('A backup already exists at the selected destination.');
        }

        return $destination;
    }

    private function writableDirectory(string $directory): string
    {
        $resolved = realpath($directory);

        if (! is_string($resolved) || ! is_dir($resolved) || ! is_writable($resolved)) {
            throw new BackupArchiveException('The backup working directory is not writable.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function privateWorkingDirectory(): string
    {
        $directory = storage_path('app/private/backup-work');

        if (! is_dir($directory)
            && (! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new BackupArchiveException('The private backup working directory could not be created.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new BackupArchiveException('The private backup working directory could not be secured.');
        }

        return $this->writableDirectory($directory);
    }

    private function temporaryPath(string $directory, string $filename): string
    {
        return $directory.DIRECTORY_SEPARATOR.'.'.$filename.'.'.bin2hex(random_bytes(8)).'.tmp.msbackup';
    }

    /** @return array{size: int, mtime: int, dev: int, ino: int} */
    private function validStreamStat(mixed $stream, int $maximumBytes): array
    {
        $stat = fstat($stream);

        if ($stat === false
            || $stat['size'] < 1
            || $stat['size'] > $maximumBytes
        ) {
            throw new BackupArchiveException('The backup archive could not be inspected safely.');
        }

        return [
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
        ];
    }

    /** @param array{size: int, mtime: int, dev: int, ino: int} $expected */
    private function assertPathStillReferencesStream(string $path, array $expected): void
    {
        $stat = @stat($path);

        if (! is_array($stat)
            || $stat['size'] !== $expected['size']
            || $stat['mtime'] !== $expected['mtime']
            || $stat['dev'] !== $expected['dev']
            || $stat['ino'] !== $expected['ino']) {
            throw new BackupArchiveException('The source backup changed while encryption was prepared.');
        }
    }

    /** @param array{size: int, mtime: int, dev: int, ino: int} $expected */
    private function assertStreamUnchanged(mixed $stream, array $expected): void
    {
        $actual = $this->validStreamStat($stream, self::MAXIMUM_ENCRYPTED_ARCHIVE_BYTES);

        if ($actual !== $expected) {
            throw new BackupArchiveException('The backup archive changed while it was being processed.');
        }
    }

    /** @return array{size: int, sha256: string} */
    private function hashStream(mixed $stream): array
    {
        $hash = hash_init('sha256');
        $size = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, MsBackupEncryptionParameters::DEFAULT_CHUNK_BYTES);

            if ($chunk === false) {
                throw new BackupArchiveException('The backup archive could not be inspected safely.');
            }

            if ($chunk === '') {
                continue;
            }

            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    private function rewindStream(mixed $stream): void
    {
        if (fseek($stream, 0, SEEK_SET) !== 0) {
            throw new BackupArchiveException('The backup archive could not be read safely.');
        }
    }

    private function encryptedSizeEstimate(int $plaintextSize, int $chunkBytes): int
    {
        $frames = (int) ceil($plaintextSize / $chunkBytes) + 1;
        $frameOverhead = $frames * (4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);

        return $plaintextSize + $frameOverhead + self::MAXIMUM_ENVELOPE_BYTES
            + strlen(self::MAGIC) + 4 + self::MINIMUM_FREE_SPACE_OVERHEAD;
    }

    private function assertFreeSpace(string $directory, int $requiredBytes): void
    {
        $available = @disk_free_space($directory);

        if ($available === false) {
            throw new BackupArchiveException('Available backup disk space could not be determined.');
        }

        if ($available < $requiredBytes) {
            throw new BackupArchiveException('There is not enough free disk space for the encrypted backup operation.');
        }
    }

    private function writeFrame(mixed $stream, string $ciphertext, HashContext $hash, int &$size): void
    {
        $this->writeAndHash($stream, pack('N', strlen($ciphertext)), $hash, $size);
        $this->writeAndHash($stream, $ciphertext, $hash, $size);
    }

    private function writeAndHash(mixed $stream, string $bytes, HashContext $hash, int &$size): void
    {
        $this->writeAll($stream, $bytes);
        hash_update($hash, $bytes);
        $size += strlen($bytes);
    }

    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));

            if (! is_int($written) || $written < 1) {
                throw new BackupArchiveException('A backup file could not be written safely.');
            }

            $offset += $written;
        }
    }

    private function readAndHashExact(
        mixed $stream,
        int $length,
        HashContext $hash,
        int &$size,
        bool $encryptedFrame,
    ): string {
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = fread($stream, max(1, $length - strlen($bytes)));

            if ($chunk === false || $chunk === '') {
                if ($encryptedFrame) {
                    throw $this->authenticationFailure();
                }

                throw new BackupArchiveException('The encrypted backup envelope is truncated.');
            }

            $bytes .= $chunk;
        }

        hash_update($hash, $bytes);
        $size += strlen($bytes);

        return $bytes;
    }

    private function flushFile(mixed $stream): void
    {
        if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
            throw new BackupArchiveException('A backup file could not be finalized safely.');
        }
    }

    private function restrictTemporaryFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
            throw new BackupArchiveException('A temporary backup file could not be secured.');
        }
    }

    private function publishWithoutOverwrite(string $temporaryPath, string $destination, string $kind): void
    {
        // Both names are in the same directory. Creating a hard link is an
        // atomic no-replace operation on NTFS and POSIX filesystems, unlike
        // rename(), which may silently overwrite an existing destination.
        if (file_exists($destination) || is_link($destination) || ! @link($temporaryPath, $destination)) {
            throw new BackupArchiveException("The {$kind} backup could not be published atomically without overwriting a file.");
        }

        if (! @unlink($temporaryPath)) {
            throw new BackupArchiveException("The {$kind} backup was published, but its temporary link could not be removed.");
        }
    }

    private function portableFilesystemPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private function authenticationFailure(): BackupArchiveException
    {
        return new BackupArchiveException(
            'The encrypted backup could not be authenticated. The passphrase may be incorrect or the file may be damaged.',
        );
    }
}
