<?php

namespace App\Backups;

use Closure;
use JsonException;

final class SupervisorOfflineRestoreGuard implements OfflineRestoreGuard
{
    private const MAXIMUM_RESPONSE_BYTES = 2048;

    /** @param null|Closure(SupervisorRestoreLease): void $probe */
    public function __construct(
        private readonly SupervisorRestoreLease $lease,
        private readonly ?Closure $probe = null,
    ) {}

    /** @param resource $stream */
    public static function fromStream(mixed $stream, string $operationId): self
    {
        return new self(SupervisorRestoreLease::fromStream($stream, $operationId));
    }

    public function assertExclusiveProcessOwnership(): void
    {
        $this->assertSupervisorLease();
    }

    public function assertStillExclusive(): void
    {
        $this->assertSupervisorLease();
    }

    private function assertSupervisorLease(): void
    {
        $this->lease->assertFresh();

        if ($this->probe instanceof Closure) {
            ($this->probe)($this->lease);

            return;
        }

        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $request = $this->encode([
            'protocol' => SupervisorRestoreLease::PROTOCOL,
            'version' => SupervisorRestoreLease::VERSION,
            'operation_id' => $this->lease->operationId,
            'expires_at_unix' => $this->lease->expiresAtUnix,
            'challenge' => $challenge,
            'proof' => $this->lease->requestProof($challenge),
        ])."\n";
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:'.$this->lease->port,
            $errorCode,
            $errorMessage,
            1.0,
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            throw new BackupArchiveException('The native restore supervisor lease is no longer reachable.');
        }

        try {
            stream_set_timeout($socket, 1, 0);
            $this->writeAll($socket, $request);
            $line = fgets($socket, self::MAXIMUM_RESPONSE_BYTES + 2);
            $metadata = stream_get_meta_data($socket);

            if (! is_string($line) || strlen($line) > self::MAXIMUM_RESPONSE_BYTES
                || ! str_ends_with($line, "\n") || $metadata['timed_out'] === true) {
                throw new BackupArchiveException('The native restore supervisor lease did not answer safely.');
            }
        } finally {
            fclose($socket);
        }

        try {
            $response = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupArchiveException('The native restore supervisor lease response is malformed.');
        }

        if (! is_array($response) || array_is_list($response)) {
            throw new BackupArchiveException('The native restore supervisor lease response is malformed.');
        }

        $responseKeys = array_keys($response);
        sort($responseKeys, SORT_STRING);

        if ($responseKeys !== [
            'challenge',
            'expires_at_unix',
            'ok',
            'operation_id',
            'proof',
            'protocol',
            'version',
        ]
            || ($response['protocol'] ?? null) !== SupervisorRestoreLease::PROTOCOL
            || ($response['version'] ?? null) !== SupervisorRestoreLease::VERSION
            || ($response['ok'] ?? null) !== true
            || ($response['operation_id'] ?? null) !== $this->lease->operationId
            || ($response['expires_at_unix'] ?? null) !== $this->lease->expiresAtUnix
            || ($response['challenge'] ?? null) !== $challenge
            || ! is_string($response['proof'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $response['proof']) !== 1
            || ! hash_equals($this->lease->responseProof($challenge), $response['proof'])) {
            throw new BackupArchiveException('The native restore supervisor lease could not be authenticated.');
        }
    }

    /** @param array<string, bool|int|string> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new BackupArchiveException('The native restore supervisor request could not be encoded.');
        }
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $payload): void
    {
        $offset = 0;

        while ($offset < strlen($payload)) {
            $written = fwrite($stream, substr($payload, $offset));

            if (! is_int($written) || $written < 1) {
                throw new BackupArchiveException('The native restore supervisor request could not be sent.');
            }

            $offset += $written;
        }

        if (! fflush($stream)) {
            throw new BackupArchiveException('The native restore supervisor request could not be sent.');
        }
    }
}
