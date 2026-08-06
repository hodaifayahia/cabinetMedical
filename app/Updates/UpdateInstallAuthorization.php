<?php

namespace App\Updates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class UpdateInstallAuthorization
{
    public const PROTOCOL = 'medismart-update-install-authorization';

    public const VERSION = 1;

    /**
     * @return array{
     *     protocol: string,
     *     version: int,
     *     target_version: string,
     *     backup_record_id: string,
     *     backup_sha256: string,
     *     installation_id: string,
     *     issued_at: int,
     *     expires_at: int,
     *     nonce: string,
     *     signature: string
     * }
     */
    public function issue(
        string $targetVersion,
        string $backupRecordId,
        string $backupSha256,
        string $installationId,
        ?CarbonImmutable $now = null,
        ?string $nonce = null,
    ): array {
        if (! $this->validVersion($targetVersion)
            || ! Str::isUuid($backupRecordId)
            || preg_match('/\A[0-9a-f]{64}\z/', $backupSha256) !== 1
            || ! Str::isUuid($installationId)) {
            throw new RuntimeException('The update installation authorization inputs are invalid.');
        }

        $key = config('app.key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('The application key is unavailable.');
        }

        $issuedAt = ($now ?? CarbonImmutable::now('UTC'))->getTimestamp();
        $ttl = max(60, min(
            600,
            (int) config('medismart.updates.install_authorization_ttl_seconds', 300),
        ));
        $payload = [
            'protocol' => self::PROTOCOL,
            'version' => self::VERSION,
            'target_version' => $targetVersion,
            'backup_record_id' => $backupRecordId,
            'backup_sha256' => $backupSha256,
            'installation_id' => $installationId,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + $ttl,
            'nonce' => $nonce ?? (string) Str::uuid(),
        ];

        if (! Str::isUuid($payload['nonce'])) {
            throw new RuntimeException('The update installation authorization nonce is invalid.');
        }

        return $payload + [
            'signature' => hash_hmac('sha256', self::canonicalPayload($payload), $key),
        ];
    }

    /** @param array<string, int|string> $payload */
    public static function canonicalPayload(array $payload): string
    {
        return implode("\n", [
            $payload['protocol'] ?? '',
            $payload['version'] ?? '',
            $payload['target_version'] ?? '',
            $payload['backup_record_id'] ?? '',
            $payload['backup_sha256'] ?? '',
            $payload['installation_id'] ?? '',
            $payload['issued_at'] ?? '',
            $payload['expires_at'] ?? '',
            $payload['nonce'] ?? '',
        ]);
    }

    private function validVersion(string $version): bool
    {
        return strlen($version) <= 64
            && preg_match(
                '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/',
                $version,
            ) === 1;
    }
}
