<?php

namespace App\Backups;

use Illuminate\Support\Str;
use JsonException;

final readonly class SupervisorRestoreLease
{
    public const PROTOCOL = 'medismart-offline-restore-lease';

    public const VERSION = 1;

    private const MAXIMUM_CAPABILITY_BYTES = 2048;

    private const MAXIMUM_LEASE_SECONDS = 4 * 60 * 60;

    private string $binarySecret;

    private function __construct(
        public string $operationId,
        public int $port,
        public int $expiresAtUnix,
        string $binarySecret,
    ) {
        $this->binarySecret = $binarySecret;
    }

    /** @param resource $stream */
    public static function fromStream(mixed $stream, string $expectedOperationId): self
    {
        if (PHP_SAPI !== 'cli' || ! is_resource($stream) || ! Str::isUuid($expectedOperationId)) {
            throw new BackupArchiveException('The native restore lease is unavailable.');
        }

        $line = fgets($stream, self::MAXIMUM_CAPABILITY_BYTES + 2);

        if (! is_string($line) || strlen($line) > self::MAXIMUM_CAPABILITY_BYTES
            || ! str_ends_with($line, "\n")) {
            throw new BackupArchiveException('The native restore lease is malformed.');
        }

        try {
            $payload = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupArchiveException('The native restore lease is malformed.');
        }

        $expectedKeys = [
            'expires_at_unix',
            'operation_id',
            'port',
            'protocol',
            'secret',
            'version',
        ];

        if (! is_array($payload) || array_is_list($payload)) {
            throw new BackupArchiveException('The native restore lease is malformed.');
        }

        $keys = array_keys($payload);
        sort($keys, SORT_STRING);

        if ($keys !== $expectedKeys
            || ($payload['protocol'] ?? null) !== self::PROTOCOL
            || ($payload['version'] ?? null) !== self::VERSION
            || ($payload['operation_id'] ?? null) !== $expectedOperationId
            || ! is_int($payload['port'] ?? null)
            || $payload['port'] < 1024 || $payload['port'] > 65535
            || ! is_int($payload['expires_at_unix'] ?? null)
            || ! is_string($payload['secret'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $payload['secret']) !== 1) {
            throw new BackupArchiveException('The native restore lease is malformed.');
        }

        $now = time();

        if ($payload['expires_at_unix'] <= $now
            || $payload['expires_at_unix'] > $now + self::MAXIMUM_LEASE_SECONDS) {
            throw new BackupArchiveException('The native restore lease is expired or exceeds its safety window.');
        }

        $binarySecret = self::decodeBase64Url($payload['secret']);

        if (! is_string($binarySecret) || strlen($binarySecret) !== 32) {
            throw new BackupArchiveException('The native restore lease is malformed.');
        }

        return new self(
            operationId: $expectedOperationId,
            port: $payload['port'],
            expiresAtUnix: $payload['expires_at_unix'],
            binarySecret: $binarySecret,
        );
    }

    public function assertFresh(): void
    {
        if (time() >= $this->expiresAtUnix) {
            throw new BackupArchiveException('The native restore lease expired.');
        }
    }

    public function requestProof(string $challenge): string
    {
        return hash_hmac('sha256', $this->proofMessage('request', $challenge), $this->binarySecret);
    }

    public function responseProof(string $challenge): string
    {
        return hash_hmac('sha256', $this->proofMessage('response', $challenge), $this->binarySecret);
    }

    private function proofMessage(string $direction, string $challenge): string
    {
        return 'medismart-restore-lease-'.$direction."-v1\n"
            .$this->operationId."\n"
            .$challenge."\n"
            .$this->expiresAtUnix;
    }

    private static function decodeBase64Url(string $encoded): string|false
    {
        $padding = (4 - strlen($encoded) % 4) % 4;

        return base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
    }
}
