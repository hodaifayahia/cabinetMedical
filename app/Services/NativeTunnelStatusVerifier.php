<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use JsonException;
use Throwable;

final class NativeTunnelStatusVerifier
{
    private const int MAX_STATUS_BYTES = 16 * 1024;

    private const string SIGNATURE_DOMAIN = 'medismart-native-tunnel-status-v1';

    /** @var list<string> */
    private const array FIELDS = [
        'schema_version',
        'runtime_instance_id',
        'installation_id',
        'application_version',
        'configured_hostname',
        'phase',
        'listener_origin',
        'cloudflared_version',
        'executable_verified',
        'retry_count',
        'last_error_code',
        'updated_at_unix_ms',
        'sequence',
        'signature',
    ];

    /** @var list<string> */
    private const array PHASES = [
        'starting',
        'ready',
        'retrying',
        'unavailable',
        'stopping',
        'stopped',
    ];

    public function verify(string $expectedHostname): NativeTunnelStatusEvidence
    {
        [
            'path' => $path,
            'authentication_key' => $authenticationKey,
            'installation_id' => $installationId,
            'application_version' => $applicationVersion,
            'maximum_age_ms' => $maximumAgeMs,
            'future_tolerance_ms' => $futureToleranceMs,
        ] = $this->configuration();

        $payload = $this->readPayload($path);
        $this->validatePayload($payload);
        $this->authenticate($payload, $authenticationKey);

        if ($payload['installation_id'] !== $installationId
            || $payload['application_version'] !== $applicationVersion) {
            throw new NativeTunnelStatusException('native_tunnel_status_identity_mismatch');
        }

        $this->validateExpectedHostname($payload, $expectedHostname);
        $this->validateFreshness($payload['updated_at_unix_ms'], $maximumAgeMs, $futureToleranceMs);
        $this->preventReplay($payload, $path, $installationId, $applicationVersion);

        return new NativeTunnelStatusEvidence(
            runtimeInstanceId: $payload['runtime_instance_id'],
            installationId: $payload['installation_id'],
            applicationVersion: $payload['application_version'],
            configuredHostname: $payload['configured_hostname'],
            phase: $payload['phase'],
            listenerOrigin: $payload['listener_origin'],
            cloudflaredVersion: $payload['cloudflared_version'],
            executableVerified: $payload['executable_verified'],
            retryCount: $payload['retry_count'],
            lastErrorCode: $payload['last_error_code'],
            updatedAtUnixMs: $payload['updated_at_unix_ms'],
            sequence: $payload['sequence'],
            signature: $payload['signature'],
        );
    }

    /**
     * @return array{
     *     path: string,
     *     authentication_key: string,
     *     installation_id: string,
     *     application_version: string,
     *     maximum_age_ms: int,
     *     future_tolerance_ms: int
     * }
     */
    private function configuration(): array
    {
        $path = config('medismart.runtime.native_tunnel_status_path');
        $authenticationKey = config('medismart.health.details_key');
        $installationId = config('medismart.runtime.installation_id');
        $applicationVersion = config('medismart.version');
        $maximumAgeMs = config('medismart.runtime.native_tunnel_status_maximum_age_ms');
        $futureToleranceMs = config('medismart.runtime.native_tunnel_status_future_tolerance_ms');

        if (config('medismart.runtime.desktop_supervised') !== true
            || ! is_string($path)
            || $path === ''
            || $this->basename($path) !== 'tunnel-public-status.json'
            || ! is_string($authenticationKey)
            || strlen($authenticationKey) < 32
            || strlen($authenticationKey) > 1024
            || str_contains($authenticationKey, "\0")
            || ! is_string($installationId)
            || ! $this->validUuid($installationId)
            || ! is_string($applicationVersion)
            || ! $this->validApplicationVersion($applicationVersion)
            || ! is_int($maximumAgeMs)
            || $maximumAgeMs < 1000
            || $maximumAgeMs > 120_000
            || ! is_int($futureToleranceMs)
            || $futureToleranceMs < 0
            || $futureToleranceMs > 30_000) {
            throw new NativeTunnelStatusException('native_tunnel_status_configuration_invalid');
        }

        return [
            'path' => $path,
            'authentication_key' => $authenticationKey,
            'installation_id' => $installationId,
            'application_version' => $applicationVersion,
            'maximum_age_ms' => $maximumAgeMs,
            'future_tolerance_ms' => $futureToleranceMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = @lstat($path);
        if (! is_array($metadata)
            || ($metadata['mode'] & 0170000) !== 0100000
            || is_link($path)
            || $metadata['size'] < 1
            || $metadata['size'] > self::MAX_STATUS_BYTES
            || (DIRECTORY_SEPARATOR === '/' && ($metadata['mode'] & 0077) !== 0)) {
            throw new NativeTunnelStatusException('native_tunnel_status_unavailable');
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new NativeTunnelStatusException('native_tunnel_status_unavailable');
        }

        try {
            $openedMetadata = fstat($stream);
            if (! is_array($openedMetadata)
                || $openedMetadata['size'] !== $metadata['size']
                || $openedMetadata['dev'] !== $metadata['dev']
                || $openedMetadata['ino'] !== $metadata['ino']) {
                throw new NativeTunnelStatusException('native_tunnel_status_unavailable');
            }

            $raw = stream_get_contents($stream, self::MAX_STATUS_BYTES + 1);
            $finalMetadata = fstat($stream);
            if (! is_string($raw)
                || strlen($raw) !== $metadata['size']
                || ! is_array($finalMetadata)
                || $finalMetadata['size'] !== $metadata['size']) {
                throw new NativeTunnelStatusException('native_tunnel_status_unavailable');
            }
        } finally {
            fclose($stream);
        }

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new NativeTunnelStatusException('native_tunnel_status_invalid');
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new NativeTunnelStatusException('native_tunnel_status_invalid');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        $actualFields = array_keys($payload);
        $expectedFields = self::FIELDS;
        sort($actualFields, SORT_STRING);
        sort($expectedFields, SORT_STRING);

        if ($actualFields !== $expectedFields
            || $payload['schema_version'] !== 1
            || ! is_string($payload['runtime_instance_id'])
            || ! $this->validUuid($payload['runtime_instance_id'])
            || ! is_string($payload['installation_id'])
            || ! $this->validUuid($payload['installation_id'])
            || ! is_string($payload['application_version'])
            || ! $this->validApplicationVersion($payload['application_version'])
            || ! $this->nullableString($payload['configured_hostname'])
            || ($payload['configured_hostname'] !== null && ! $this->validHostname($payload['configured_hostname']))
            || ! is_string($payload['phase'])
            || ! in_array($payload['phase'], self::PHASES, true)
            || ! $this->nullableString($payload['listener_origin'])
            || ($payload['listener_origin'] !== null && ! $this->validLoopbackOrigin($payload['listener_origin']))
            || ! $this->nullableString($payload['cloudflared_version'])
            || ($payload['cloudflared_version'] !== null && ! $this->validCloudflaredVersion($payload['cloudflared_version']))
            || ! is_bool($payload['executable_verified'])
            || ! is_int($payload['retry_count'])
            || $payload['retry_count'] < 0
            || $payload['retry_count'] > 255
            || ! $this->nullableString($payload['last_error_code'])
            || ($payload['last_error_code'] !== null && preg_match('/\A[a-z0-9_]{1,96}\z/D', $payload['last_error_code']) !== 1)
            || ! is_int($payload['updated_at_unix_ms'])
            || $payload['updated_at_unix_ms'] < 1
            || ! is_int($payload['sequence'])
            || $payload['sequence'] < 1
            || ! is_string($payload['signature'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $payload['signature']) !== 1) {
            throw new NativeTunnelStatusException('native_tunnel_status_invalid');
        }

        if ($payload['phase'] === 'ready'
            && ($payload['configured_hostname'] === null
                || $payload['listener_origin'] === null
                || $payload['cloudflared_version'] === null
                || $payload['executable_verified'] !== true)) {
            throw new NativeTunnelStatusException('native_tunnel_status_invalid');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function authenticate(array $payload, string $authenticationKey): void
    {
        $fields = [
            (string) $payload['schema_version'],
            $payload['runtime_instance_id'],
            $payload['installation_id'],
            $payload['application_version'],
            $payload['configured_hostname'] ?? '',
            $payload['phase'],
            $payload['listener_origin'] ?? '',
            $payload['cloudflared_version'] ?? '',
            $payload['executable_verified'] ? '1' : '0',
            (string) $payload['retry_count'],
            $payload['last_error_code'] ?? '',
            (string) $payload['updated_at_unix_ms'],
            (string) $payload['sequence'],
        ];

        $framed = $this->frame(self::SIGNATURE_DOMAIN);
        foreach ($fields as $field) {
            $framed .= $this->frame($field);
        }

        $expected = hash_hmac('sha256', $framed, $authenticationKey);
        if (! hash_equals($expected, $payload['signature'])) {
            throw new NativeTunnelStatusException('native_tunnel_status_authentication_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateExpectedHostname(array $payload, string $expectedHostname): void
    {
        if (! $this->validHostname($expectedHostname)
            || ($payload['configured_hostname'] !== null && $payload['configured_hostname'] !== $expectedHostname)
            || ($payload['phase'] === 'ready' && $payload['configured_hostname'] !== $expectedHostname)) {
            throw new NativeTunnelStatusException('native_tunnel_status_hostname_mismatch');
        }
    }

    private function validateFreshness(int $updatedAtUnixMs, int $maximumAgeMs, int $futureToleranceMs): void
    {
        $nowUnixMs = Date::now()->getTimestampMs();

        if ($updatedAtUnixMs > $nowUnixMs + $futureToleranceMs) {
            throw new NativeTunnelStatusException('native_tunnel_status_from_future');
        }
        if ($updatedAtUnixMs < $nowUnixMs - $maximumAgeMs) {
            throw new NativeTunnelStatusException('native_tunnel_status_stale');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preventReplay(
        array $payload,
        string $path,
        string $installationId,
        string $applicationVersion,
    ): void {
        $scope = hash('sha256', $path."\0".$installationId."\0".$applicationVersion);
        $stateKey = "medismart:native-tunnel-status:replay:v1:{$scope}";
        $lock = Cache::lock("{$stateKey}:lock", 5);

        try {
            $lock->block(2, function () use ($payload, $stateKey): void {
                $previous = Cache::get($stateKey);
                if ($previous !== null && ! $this->validReplayState($previous)) {
                    throw new NativeTunnelStatusException('native_tunnel_status_replay_store_unavailable');
                }

                if (is_array($previous)) {
                    $sameRuntime = $previous['runtime_instance_id'] === $payload['runtime_instance_id'];
                    if (($sameRuntime && $payload['sequence'] < $previous['sequence'])
                        || ($sameRuntime
                            && $payload['sequence'] === $previous['sequence']
                            && ! hash_equals($previous['signature'], $payload['signature']))
                        || ($sameRuntime && $payload['updated_at_unix_ms'] < $previous['updated_at_unix_ms'])
                        || (! $sameRuntime && $payload['updated_at_unix_ms'] <= $previous['updated_at_unix_ms'])) {
                        throw new NativeTunnelStatusException('native_tunnel_status_replayed');
                    }

                    if ($sameRuntime
                        && $payload['sequence'] === $previous['sequence']
                        && hash_equals($previous['signature'], $payload['signature'])) {
                        return;
                    }
                }

                $stored = Cache::forever($stateKey, [
                    'runtime_instance_id' => $payload['runtime_instance_id'],
                    'sequence' => $payload['sequence'],
                    'updated_at_unix_ms' => $payload['updated_at_unix_ms'],
                    'signature' => $payload['signature'],
                ]);

                if ($stored !== true) {
                    throw new NativeTunnelStatusException('native_tunnel_status_replay_store_unavailable');
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof NativeTunnelStatusException) {
                throw $exception;
            }

            throw new NativeTunnelStatusException('native_tunnel_status_replay_store_unavailable');
        }
    }

    private function validReplayState(mixed $state): bool
    {
        if (! is_array($state)) {
            return false;
        }

        $fields = array_keys($state);
        sort($fields, SORT_STRING);

        return $fields === ['runtime_instance_id', 'sequence', 'signature', 'updated_at_unix_ms']
            && is_string($state['runtime_instance_id'])
            && $this->validUuid($state['runtime_instance_id'])
            && is_int($state['sequence'])
            && $state['sequence'] > 0
            && is_int($state['updated_at_unix_ms'])
            && $state['updated_at_unix_ms'] > 0
            && is_string($state['signature'])
            && preg_match('/\A[0-9a-f]{64}\z/D', $state['signature']) === 1;
    }

    private function frame(string $field): string
    {
        return pack('N', strlen($field)).$field;
    }

    private function basename(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $components = explode('/', $normalized);

        return (string) end($components);
    }

    private function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private function validApplicationVersion(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 128
            && trim($value) === $value
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private function validHostname(string $value): bool
    {
        if ($value === ''
            || strlen($value) > 253
            || strtolower($value) !== $value
            || ! str_contains($value, '.')
            || $value === 'localhost'
            || str_ends_with($value, '.localhost')
            || $value === 'trycloudflare.com'
            || str_ends_with($value, '.trycloudflare.com')) {
            return false;
        }

        foreach (explode('.', $value) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || str_starts_with($label, '-')
                || str_ends_with($label, '-')
                || preg_match('/\A[a-z0-9-]+\z/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function validLoopbackOrigin(string $value): bool
    {
        if (preg_match('/\Ahttp:\/\/127\.0\.0\.1:([0-9]{1,5})\z/D', $value, $matches) !== 1) {
            return false;
        }

        $port = (int) $matches[1];

        return $port >= 1024 && $port <= 65_535 && (string) $port === $matches[1];
    }

    private function validCloudflaredVersion(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 64
            && preg_match('/\A[0-9.-]+\z/D', $value) === 1;
    }
}
