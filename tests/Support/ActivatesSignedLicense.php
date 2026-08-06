<?php

namespace Tests\Support;

use App\Services\LicenseService;
use App\Services\MachineFingerprintService;

trait ActivatesSignedLicense
{
    /** @var list<string> */
    private array $signedLicensePublicKeyPaths = [];

    /** @param array<string, bool> $features */
    protected function activateSignedLicenseFeatures(array $features): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $privateKey instanceof \OpenSSLAsymmetricKey) {
            $this->fail('OpenSSL could not generate the test license key.');
        }

        $details = openssl_pkey_get_details($privateKey);

        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            $this->fail('OpenSSL could not export the test license public key.');
        }

        $publicKeyPath = tempnam(sys_get_temp_dir(), 'medismart-feature-key-');

        if ($publicKeyPath === false || file_put_contents($publicKeyPath, $details['key']) === false) {
            $this->fail('The test license public key could not be persisted.');
        }

        $this->signedLicensePublicKeyPaths[] = $publicKeyPath;
        config()->set('medismart.licensing.product', 'medismart-desktop');
        config()->set('medismart.licensing.public_key_path', $publicKeyPath);

        $fingerprint = app(MachineFingerprintService::class);
        $payload = [
            'license_id' => 'feature-test-'.bin2hex(random_bytes(8)),
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $fingerprint->installationId(),
            'machine_fingerprint_hash' => $fingerprint->fingerprintHash(),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
            'offline_grace_days' => 2,
            'features' => $features,
        ];
        $payloadSegment = $this->base64UrlEncodeLicensePayload(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $signature = '';

        if (! openssl_sign($payloadSegment, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->fail('OpenSSL could not sign the test license certificate.');
        }

        app(LicenseService::class)->activateFromCertificate(json_encode([
            'algorithm' => 'RS256',
            'payload' => $payloadSegment,
            'signature' => $this->base64UrlEncodeLicensePayload($signature),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected function cleanUpSignedLicenseFeatures(): void
    {
        foreach ($this->signedLicensePublicKeyPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->signedLicensePublicKeyPaths = [];
    }

    private function base64UrlEncodeLicensePayload(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
