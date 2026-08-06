<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class LicenseService
{
    /** @var list<string> */
    public const FEATURES = [
        'remote_upload',
        'google_drive_backup',
        'automatic_updates',
        'multi_user',
        'custom_branding',
        'remote_relay',
    ];

    public function __construct(
        private readonly MachineFingerprintService $fingerprint,
        private readonly LicenseClockService $clock,
    ) {}

    public function normalizeSerial(#[SensitiveParameter] string $serial): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($serial)));
    }

    public function hasValidSerialFormat(#[SensitiveParameter] string $serial): bool
    {
        return preg_match('/^[A-Z0-9]{4,8}(?:-[A-Z0-9]{4,8}){2,5}$/', $this->normalizeSerial($serial)) === 1;
    }

    public function verificationReady(): bool
    {
        try {
            $this->publicKey();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verify a compact JSON certificate whose signature covers the base64url
     * payload segment: {"algorithm":"RS256","payload":"...","signature":"..."}.
     *
     * @return array<string, mixed>
     */
    public function verifyCertificate(#[SensitiveParameter] string $certificate): array
    {
        if ($certificate === '' || strlen($certificate) > 131_072) {
            throw new RuntimeException('The license certificate envelope is invalid.');
        }

        try {
            $envelope = json_decode($certificate, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The license certificate is not valid JSON.', previous: $exception);
        }

        if (! is_array($envelope)
            || ($envelope['algorithm'] ?? null) !== 'RS256'
            || ! is_string($envelope['payload'] ?? null)
            || ! is_string($envelope['signature'] ?? null)) {
            throw new RuntimeException('The license certificate envelope is invalid.');
        }

        $publicKey = $this->publicKey();
        $signature = $this->base64UrlDecode($envelope['signature']);
        $verification = openssl_verify(
            $envelope['payload'],
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verification !== 1) {
            throw new RuntimeException('The license certificate signature is invalid.');
        }

        try {
            $payload = json_decode($this->base64UrlDecode($envelope['payload']), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The signed license payload is invalid.', previous: $exception);
        }

        foreach (['license_id', 'product', 'edition', 'installation_id', 'machine_fingerprint_hash', 'issued_at'] as $required) {
            if (! is_array($payload) || ! is_string($payload[$required] ?? null) || $payload[$required] === '') {
                throw new RuntimeException("The signed license payload is missing {$required}.");
            }
        }

        if (! is_int($payload['certificate_version'] ?? null)
            || $payload['certificate_version'] < 1
            || $payload['certificate_version'] > 2_147_483_647) {
            throw new RuntimeException('The signed license certificate version is invalid.');
        }

        if (strlen($payload['license_id']) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $payload['license_id']) !== 1) {
            throw new RuntimeException('The signed license identifier is invalid.');
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $payload['edition']) !== 1) {
            throw new RuntimeException('The signed license edition is invalid.');
        }

        if (! Str::isUuid($payload['installation_id'])) {
            throw new RuntimeException('The signed installation identifier is invalid.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $payload['machine_fingerprint_hash']) !== 1) {
            throw new RuntimeException('The signed machine fingerprint is invalid.');
        }

        $customerId = $payload['customer_id'] ?? null;

        if ($customerId !== null
            && (! is_string($customerId) || $customerId === '' || strlen($customerId) > 190)) {
            throw new RuntimeException('The signed customer identifier is invalid.');
        }

        $features = $payload['features'] ?? [];

        if (! is_array($features)) {
            throw new RuntimeException('The signed license features are invalid.');
        }

        foreach ($features as $feature => $enabled) {
            if (! is_string($feature)
                || ! in_array($feature, self::FEATURES, true)
                || ! is_bool($enabled)) {
                throw new RuntimeException('The signed license features are invalid.');
            }
        }

        if (! hash_equals((string) config('medismart.licensing.product'), $payload['product'])) {
            throw new RuntimeException('The license was issued for another product.');
        }

        return $payload;
    }

    public function activateFromCertificate(#[SensitiveParameter] string $certificate): License
    {
        return $this->persistCertificate($certificate, null, 'license.activated');
    }

    /** @return array<string, mixed> */
    public function verifyCertificateForCurrentInstallation(
        #[SensitiveParameter] string $certificate,
    ): array {
        $payload = $this->verifyCertificate($certificate);

        if (! hash_equals(
            $this->fingerprint->installationId(),
            (string) $payload['installation_id'],
        )) {
            throw new RuntimeException('The license was issued for another installation.');
        }

        if (! hash_equals(
            $this->fingerprint->fingerprintHash(),
            (string) $payload['machine_fingerprint_hash'],
        )) {
            throw new RuntimeException('The license was issued for another machine.');
        }

        return $payload;
    }

    public function refreshFromCertificate(
        #[SensitiveParameter] string $certificate,
        License $current,
    ): License {
        return $this->persistCertificate(
            $certificate,
            $current->license_id,
            'license.refreshed',
        );
    }

    /**
     * @return array{
     *     state: string,
     *     edition: string|null,
     *     expires_at: string|null,
     *     offline_grace_until: string|null,
     *     last_verified_at: string|null,
     *     clock_warning: bool
     * }
     */
    public function status(): array
    {
        $trusted = $this->trustedState();

        return [
            'state' => $trusted['state'],
            'edition' => $trusted['edition'],
            'expires_at' => $trusted['expires_at'],
            'offline_grace_until' => $trusted['offline_grace_until'],
            'last_verified_at' => $trusted['last_verified_at'],
            'clock_warning' => $trusted['clock_warning'],
        ];
    }

    public function featureEnabled(string $feature): bool
    {
        $trusted = $this->trustedState();

        if (! in_array($trusted['state'], ['active', 'offline_grace'], true)) {
            return false;
        }

        return ($trusted['features'][$feature] ?? false) === true;
    }

    /**
     * Rebuild the effective license state from authenticated certificate data.
     * Database columns are only a local projection and never grant access.
     *
     * @return array{
     *     state: string,
     *     edition: string|null,
     *     expires_at: string|null,
     *     offline_grace_until: string|null,
     *     last_verified_at: string|null,
     *     clock_warning: bool,
     *     features: array<string, mixed>
     * }
     */
    private function trustedState(): array
    {
        $license = License::query()->latest('id')->first();

        if ($license === null) {
            return $this->emptyState('not_activated');
        }

        try {
            $payload = $this->verifyCertificateForCurrentInstallation($license->signed_certificate);

            if (! hash_equals($license->license_id, (string) $payload['license_id'])) {
                throw new RuntimeException('The stored license does not match its signed certificate.');
            }

            $issuedAt = $this->parseSignedTimestamp($payload['issued_at'], 'issued_at');

            $expiresAt = $this->signedExpiry($payload);
            $offlineGraceUntil = $expiresAt?->addDays($this->signedOfflineGraceDays($payload));
            $state = $this->signedStatus($payload);
            $clock = $this->clock->evaluate($issuedAt);
            $effectiveNow = $clock['effective_now'];

            if ($state === 'active'
                && $expiresAt !== null
                && $expiresAt->lessThanOrEqualTo($effectiveNow)) {
                $state = $offlineGraceUntil !== null && $offlineGraceUntil->isAfter($effectiveNow)
                    ? 'offline_grace'
                    : 'expired';
            }

            if ($clock['rollback_detected'] && in_array($state, ['active', 'offline_grace'], true)) {
                $state = 'clock_rollback';
            }

            $features = $payload['features'] ?? [];

            return [
                'state' => $state,
                'edition' => $payload['edition'],
                'expires_at' => $expiresAt?->toIso8601String(),
                'offline_grace_until' => $offlineGraceUntil?->toIso8601String(),
                'last_verified_at' => $issuedAt->toIso8601String(),
                'clock_warning' => $clock['rollback_detected'],
                'features' => is_array($features) ? $features : [],
            ];
        } catch (Throwable) {
            return $this->emptyState('invalid');
        }
    }

    /** @param array<string, mixed> $payload */
    private function signedExpiry(array $payload): ?CarbonImmutable
    {
        $expiresAt = $payload['expires_at'] ?? null;

        if ($expiresAt === null) {
            return null;
        }

        if (! is_string($expiresAt) || $expiresAt === '') {
            throw new RuntimeException('The signed license expiry is invalid.');
        }

        return $this->parseSignedTimestamp($expiresAt, 'expires_at');
    }

    /** @param array<string, mixed> $payload */
    private function signedOfflineGraceDays(array $payload): int
    {
        $days = $payload['offline_grace_days'] ?? 0;

        if (! is_int($days) || $days < 0 || $days > 3650) {
            throw new RuntimeException('The signed offline grace period is invalid.');
        }

        return $days;
    }

    /** @param array<string, mixed> $payload */
    private function signedStatus(array $payload): string
    {
        $status = $payload['status'] ?? 'active';

        if (! is_string($status)
            || ! in_array($status, ['active', 'expired', 'suspended', 'revoked', 'device_limit_reached'], true)) {
            throw new RuntimeException('The signed license status is invalid.');
        }

        return $status;
    }

    /**
     * @return array{
     *     state: string,
     *     edition: null,
     *     expires_at: null,
     *     offline_grace_until: null,
     *     last_verified_at: null,
     *     clock_warning: false,
     *     features: array<string, mixed>
     * }
     */
    private function emptyState(string $state): array
    {
        return [
            'state' => $state,
            'edition' => null,
            'expires_at' => null,
            'offline_grace_until' => null,
            'last_verified_at' => null,
            'clock_warning' => false,
            'features' => [],
        ];
    }

    private function persistCertificate(
        #[SensitiveParameter] string $certificate,
        ?string $expectedLicenseId,
        string $auditAction,
    ): License {
        $payload = $this->verifyCertificateForCurrentInstallation($certificate);
        $installationId = $this->fingerprint->installationId();

        if ($expectedLicenseId !== null
            && ! hash_equals($expectedLicenseId, (string) $payload['license_id'])) {
            throw new RuntimeException('The refreshed certificate belongs to another license.');
        }

        $issuedAt = $this->parseSignedTimestamp($payload['issued_at'], 'issued_at');
        $expiresAt = $this->signedExpiry($payload);
        $graceDays = $this->signedOfflineGraceDays($payload);
        $signedStatus = $this->signedStatus($payload);

        return DB::transaction(function () use (
            $certificate,
            $payload,
            $installationId,
            $issuedAt,
            $expiresAt,
            $graceDays,
            $signedStatus,
            $auditAction,
            $expectedLicenseId,
        ): License {
            $current = License::query()->latest('id')->lockForUpdate()->first();

            if ($current === null && $expectedLicenseId !== null) {
                throw new RuntimeException('The license being refreshed is no longer active.');
            }

            if ($current !== null) {
                $currentPayload = $this->verifyCertificate($current->signed_certificate);

                if (! hash_equals($current->license_id, (string) $currentPayload['license_id'])) {
                    throw new RuntimeException('The stored license does not match its signed certificate.');
                }

                if ($expectedLicenseId !== null
                    && ! hash_equals($expectedLicenseId, (string) $currentPayload['license_id'])) {
                    throw new RuntimeException('The license being refreshed is no longer active.');
                }

                if (! hash_equals(
                    (string) $currentPayload['license_id'],
                    (string) $payload['license_id'],
                )) {
                    throw new RuntimeException(
                        'Deactivate the current license before activating another license.',
                    );
                }

                if (hash_equals($current->signed_certificate, $certificate)) {
                    return $current;
                }

                $this->assertNewerCertificate($currentPayload, $payload);
            }

            $this->clock->recordServerTime($issuedAt);

            $license = License::query()->updateOrCreate(
                ['license_id' => $payload['license_id']],
                [
                    'product' => $payload['product'],
                    'edition' => $payload['edition'],
                    'customer_id' => $payload['customer_id'] ?? null,
                    'signed_certificate' => $certificate,
                    'status' => $signedStatus,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'offline_grace_until' => $expiresAt?->addDays($graceDays),
                    'last_verified_at' => $issuedAt,
                    'last_server_response' => [
                        'features' => is_array($payload['features'] ?? null) ? $payload['features'] : [],
                        'offline_grace_days' => $graceDays,
                    ],
                ],
            );

            // A desktop installation has one effective certificate. Keeping an
            // older signed row would otherwise resurrect it after deactivation.
            License::query()->whereKeyNot($license->getKey())->delete();

            $device = $this->fingerprint->registerDevice();
            $activation = LicenseActivation::query()->firstOrNew([
                'license_id' => $license->getKey(),
                'installation_id' => $installationId,
            ]);

            if (! $activation->exists) {
                $activation->activated_at = $issuedAt;
            }

            $activation->fill([
                'device_id' => $device->getKey(),
                'machine_fingerprint_hash' => $device->machine_fingerprint_hash,
                'last_seen_at' => $issuedAt,
                'deactivated_at' => null,
                'status' => $signedStatus,
            ])->save();

            AuditLog::record($auditAction, $license, [
                'edition' => $license->edition,
                'installation_id' => $installationId,
                'state' => $signedStatus,
            ]);

            return $license;
        });
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $candidate
     */
    private function assertNewerCertificate(array $current, array $candidate): void
    {
        if ((int) $candidate['certificate_version'] <= (int) $current['certificate_version']) {
            throw new RuntimeException('The license server returned a replayed certificate.');
        }

        if ($this->parseSignedTimestamp((string) $candidate['issued_at'], 'issued_at')
            ->isBefore($this->parseSignedTimestamp((string) $current['issued_at'], 'issued_at'))) {
            throw new RuntimeException('The license server returned an older certificate.');
        }
    }

    private function parseSignedTimestamp(string $value, string $field): CarbonImmutable
    {
        if (preg_match(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D',
            $value,
        ) !== 1) {
            throw new RuntimeException("The signed license {$field} timestamp is invalid.");
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false
            || (is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException("The signed license {$field} timestamp is invalid.");
        }

        return CarbonImmutable::instance($date);
    }

    private function publicKey(): \OpenSSLAsymmetricKey
    {
        $path = (string) config('medismart.licensing.public_key_path');

        if ($path === '') {
            throw new RuntimeException('No license verification public key is configured.');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = base_path($path);
        }

        $contents = is_file($path) ? file_get_contents($path) : false;

        if (is_string($contents) && str_contains($contents, 'PRIVATE KEY-----')) {
            throw new RuntimeException('A private license signing key must never be configured in the client.');
        }

        $key = is_string($contents) ? openssl_pkey_get_public($contents) : false;

        if ($key === false) {
            throw new RuntimeException('The license verification public key could not be loaded.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ! is_int($details['bits'] ?? null)
            || $details['bits'] < 2048) {
            throw new RuntimeException('The license verification public key is not an approved RSA key.');
        }

        return $key;
    }

    private function base64UrlDecode(#[SensitiveParameter] string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new RuntimeException('The license certificate contains invalid base64url data.');
        }

        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('The license certificate contains invalid base64url data.');
        }

        return $decoded;
    }
}
