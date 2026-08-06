<?php

namespace App\Backups;

final class LocalBackupArchiveInspector
{
    private const ENCRYPTED_MAGIC = "MEDISMART-MSBAK\x02";

    private const MAXIMUM_ENVELOPE_BYTES = 64 * 1024;

    private const MAXIMUM_ENCRYPTED_BYTES = 513 * 1024 * 1024 * 1024;

    public function __construct(private readonly MsBackupArchiveVerifier $v1Verifier) {}

    /**
     * @return array{
     *     format_version: 1|2,
     *     created_at: string,
     *     size_bytes: int,
     *     sha256: string
     * }
     */
    public function inspect(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if (! is_resource($handle) || ! flock($handle, LOCK_SH | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new BackupArchiveException('The local backup could not be locked for retention verification.');
        }

        try {
            $initial = $this->validStat($handle);
            $pathStat = $this->validPathStat($path);

            if (! $this->sameFile($initial, $pathStat)) {
                throw new BackupArchiveException('The local backup path changed during retention verification.');
            }

            $sha256 = $this->hashLockedStream($handle, $initial['size']);
            $this->rewind($handle);
            $prefix = $this->readExact($handle, strlen(self::ENCRYPTED_MAGIC));

            if (str_starts_with($prefix, 'PK')) {
                $verified = $this->v1Verifier->verify($path);
                $createdAt = $verified['manifest']['created_at'] ?? null;

                if (! is_string($createdAt)
                    || $verified['archive_size'] !== $initial['size']
                    || ! hash_equals($sha256, $verified['archive_sha256'])) {
                    throw new BackupArchiveException('The local v1 backup did not match its verified stream.');
                }

                $result = [
                    'format_version' => 1,
                    'created_at' => $createdAt,
                    'size_bytes' => $initial['size'],
                    'sha256' => $sha256,
                ];
            } elseif (hash_equals(self::ENCRYPTED_MAGIC, $prefix)) {
                $result = $this->inspectEncryptedStream($handle, $initial['size'], $sha256);
            } else {
                throw new BackupArchiveException('The local backup format is not supported for retention.');
            }

            clearstatcache(true, $path);
            $final = $this->validStat($handle);
            $finalPath = $this->validPathStat($path);

            if (! $this->sameFile($initial, $final)
                || ! $this->sameFile($initial, $finalPath)) {
                throw new BackupArchiveException('The local backup changed during retention verification.');
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array{format_version: 2, created_at: string, size_bytes: int, sha256: string}
     */
    private function inspectEncryptedStream(mixed $handle, int $streamSize, string $sha256): array
    {
        $lengthBytes = $this->readExact($handle, 4);
        $unpacked = unpack('Nlength', $lengthBytes);
        $envelopeLength = is_array($unpacked) ? ($unpacked['length'] ?? null) : null;

        if (! is_int($envelopeLength)
            || $envelopeLength < 2
            || $envelopeLength > self::MAXIMUM_ENVELOPE_BYTES) {
            throw new BackupArchiveException('The encrypted local backup envelope is invalid.');
        }

        $envelope = EncryptedMsBackupEnvelope::fromJson(
            $this->readExact($handle, $envelopeLength),
        );
        $remaining = $envelope->plaintextSize;
        $authenticationBytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while ($remaining > 0) {
            $plaintextBytes = min($remaining, $envelope->chunkBytes);
            $this->assertFrameLength($handle, $plaintextBytes + $authenticationBytes);
            $this->discardExact($handle, $plaintextBytes + $authenticationBytes);
            $remaining -= $plaintextBytes;
        }

        $this->assertFrameLength($handle, $authenticationBytes);
        $this->discardExact($handle, $authenticationBytes);

        if (fread($handle, 1) !== '') {
            throw new BackupArchiveException('The encrypted local backup contains trailing data.');
        }

        return [
            'format_version' => 2,
            'created_at' => $envelope->createdAt,
            'size_bytes' => $streamSize,
            'sha256' => $sha256,
        ];
    }

    /** @param resource $handle */
    private function assertFrameLength(mixed $handle, int $expected): void
    {
        $unpacked = unpack('Nlength', $this->readExact($handle, 4));
        $length = is_array($unpacked) ? ($unpacked['length'] ?? null) : null;

        if (! is_int($length) || $length !== $expected) {
            throw new BackupArchiveException('The encrypted local backup framing is invalid.');
        }
    }

    /** @param resource $handle */
    private function discardExact(mixed $handle, int $length): void
    {
        if ($length < 1) {
            throw new BackupArchiveException('The encrypted local backup framing is invalid.');
        }

        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($handle, min($remaining, 1024 * 1024));

            if (! is_string($chunk) || $chunk === '') {
                throw new BackupArchiveException('The encrypted local backup is truncated.');
            }

            $remaining -= strlen($chunk);
        }
    }

    /** @param resource $handle */
    private function readExact(mixed $handle, int $length): string
    {
        if ($length < 1) {
            throw new BackupArchiveException('The local backup framing is invalid.');
        }

        $bytes = '';

        while (strlen($bytes) < $length) {
            $remaining = $length - strlen($bytes);

            if ($remaining < 1) {
                break;
            }

            $chunk = fread($handle, $remaining);

            if (! is_string($chunk) || $chunk === '') {
                throw new BackupArchiveException('The local backup is truncated.');
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @param resource $handle */
    private function hashLockedStream(mixed $handle, int $expectedSize): string
    {
        $this->rewind($handle);
        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $handle);

        if ($bytes !== $expectedSize) {
            throw new BackupArchiveException('The local backup size changed during hashing.');
        }

        return hash_final($hash);
    }

    /** @param resource $handle */
    private function rewind(mixed $handle): void
    {
        if (fseek($handle, 0, SEEK_SET) !== 0) {
            throw new BackupArchiveException('The local backup stream could not be rewound.');
        }
    }

    /**
     * @param  resource  $handle
     * @return array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int}
     */
    private function validStat(mixed $handle): array
    {
        $stat = fstat($handle);

        if (! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1
            || $stat['size'] < 1
            || $stat['size'] > self::MAXIMUM_ENCRYPTED_BYTES) {
            throw new BackupArchiveException('The local backup is not a supported regular file.');
        }

        return [
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'mode' => $stat['mode'],
            'nlink' => $stat['nlink'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
        ];
    }

    /** @return array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int} */
    private function validPathStat(string $path): array
    {
        $stat = @lstat($path);

        if (! is_array($stat)) {
            throw new BackupArchiveException('The local backup path changed during retention verification.');
        }

        return [
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'mode' => $stat['mode'],
            'nlink' => $stat['nlink'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
        ];
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino']
            && $left['mode'] === $right['mode']
            && $left['nlink'] === $right['nlink']
            && $left['size'] === $right['size']
            && $left['mtime'] === $right['mtime'];
    }
}
