<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A real RSA pair for the desktop activation tests: the public half is what
 * an installed desktop ships, the private half what the online service signs
 * with. Signatures are genuinely produced and verified, never stubbed.
 */
trait SignsCabinetEntitlements
{
    protected OpenSSLAsymmetricKey $entitlementPrivateKey;

    protected string $entitlementPublicKeyPath;

    protected string $entitlementPrivateKeyPath;

    protected function setUpEntitlementKeys(): void
    {
        $this->entitlementPrivateKey = $this->generateRsaKey();
        $details = openssl_pkey_get_details($this->entitlementPrivateKey);

        $this->entitlementPublicKeyPath = tempnam(sys_get_temp_dir(), 'drclick-pub').'.pem';
        file_put_contents($this->entitlementPublicKeyPath, $details['key']);

        openssl_pkey_export($this->entitlementPrivateKey, $privatePem);
        $this->entitlementPrivateKeyPath = tempnam(sys_get_temp_dir(), 'drclick-priv').'.pem';
        file_put_contents($this->entitlementPrivateKeyPath, $privatePem);

        config([
            'medismart.licensing.public_key_path' => $this->entitlementPublicKeyPath,
            'medismart.licensing.product' => 'medismart-desktop',
        ]);

        $this->beforeApplicationDestroyed(function (): void {
            @unlink($this->entitlementPublicKeyPath);
            @unlink($this->entitlementPrivateKeyPath);
        });
    }

    protected function generateRsaKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new RuntimeException('Could not generate a signing key.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function signedEntitlement(array $overrides = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        $payload = array_merge([
            'entitlement_version' => 1,
            'entitlement_id' => 'ENT-'.strtoupper(bin2hex(random_bytes(6))),
            'product' => 'medismart-desktop',
            'owner_email' => 'owner@example.com',
            'plan' => 'lifetime',
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => null,
        ], $overrides);

        $payload = array_filter($payload, static fn (mixed $value, string $claim): bool => $claim !== 'hub_id' || $value !== null, ARRAY_FILTER_USE_BOTH);

        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        openssl_sign($encoded, $signature, $key ?? $this->entitlementPrivateKey, OPENSSL_ALGO_SHA256);

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $encoded,
            'signature' => $this->base64UrlEncode($signature),
        ], JSON_THROW_ON_ERROR);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
