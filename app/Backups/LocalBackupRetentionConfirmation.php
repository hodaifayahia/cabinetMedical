<?php

namespace App\Backups;

use JsonException;

final class LocalBackupRetentionConfirmation
{
    private const PREFIX = 'msrt1';

    private const TTL_SECONDS = 300;

    public function __construct(private readonly ?string $confirmationSecret = null) {}

    public function issue(LocalBackupRetentionPreview $preview): string
    {
        $issuedAt = now()->getTimestamp();
        $payload = [
            'version' => 1,
            'plan_sha256' => $preview->plan->planSha256,
            'inventory_sha256' => $preview->inventorySha256,
            'managed_root_sha256' => $preview->managedRootSha256,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + self::TTL_SECONDS,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        try {
            $encoded = $this->encode(json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } catch (JsonException) {
            throw new BackupArchiveException('The retention confirmation could not be issued.');
        }

        $signature = hash_hmac('sha256', self::PREFIX.'.'.$encoded, $this->secret());

        return self::PREFIX.'.'.$encoded.'.'.$signature;
    }

    public function assertValid(string $token, LocalBackupRetentionPreview $preview): void
    {
        $invalid = new BackupArchiveException(
            'The retention confirmation is invalid, expired, or does not match the fresh inventory.',
        );
        $parts = explode('.', $token);

        if (count($parts) !== 3
            || $parts[0] !== self::PREFIX
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $parts[1]) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $parts[2]) !== 1) {
            throw $invalid;
        }

        $expectedSignature = hash_hmac('sha256', self::PREFIX.'.'.$parts[1], $this->secret());

        if (! hash_equals($expectedSignature, $parts[2])) {
            throw $invalid;
        }

        try {
            $decoded = json_decode($this->decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid;
        }

        $now = now()->getTimestamp();

        if (! is_array($decoded)
            || array_is_list($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ! is_string($decoded['plan_sha256'] ?? null)
            || ! is_string($decoded['inventory_sha256'] ?? null)
            || ! is_string($decoded['managed_root_sha256'] ?? null)
            || ! is_int($decoded['issued_at'] ?? null)
            || ! is_int($decoded['expires_at'] ?? null)
            || ! is_string($decoded['nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/', $decoded['nonce']) !== 1
            || $decoded['issued_at'] > $now + 30
            || $decoded['expires_at'] <= $now
            || $decoded['expires_at'] - $decoded['issued_at'] !== self::TTL_SECONDS
            || ! hash_equals($preview->plan->planSha256, $decoded['plan_sha256'])
            || ! hash_equals($preview->inventorySha256, $decoded['inventory_sha256'])
            || ! hash_equals($preview->managedRootSha256, $decoded['managed_root_sha256'])) {
            throw $invalid;
        }
    }

    private function secret(): string
    {
        $configured = $this->confirmationSecret ?? config('app.key');

        if (! is_string($configured) || $configured === '') {
            throw new BackupArchiveException('The internal retention confirmation secret is unavailable.');
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            if (! is_string($decoded)) {
                throw new BackupArchiveException('The internal retention confirmation secret is invalid.');
            }

            $configured = $decoded;
        }

        if (strlen($configured) < 32) {
            throw new BackupArchiveException('The internal retention confirmation secret is invalid.');
        }

        return $configured;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new BackupArchiveException('The retention confirmation encoding is invalid.');
        }

        return $decoded;
    }
}
