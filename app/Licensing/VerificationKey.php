<?php

namespace App\Licensing;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Loads the RSA public key that every signed licensing artefact is verified
 * against.
 *
 * This was private to LicenseService. A Cabinet Hub also has to verify
 * cabinet entitlements with no Internet connection, and two copies of these
 * rules would be two places for the "is this really a public key" checks to
 * drift apart.
 */
final class VerificationKey
{
    private const MINIMUM_RSA_BITS = 2048;

    public function isConfigured(): bool
    {
        try {
            $this->load();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function load(): OpenSSLAsymmetricKey
    {
        $path = $this->resolvePath();

        $contents = is_file($path) ? file_get_contents($path) : false;

        // A client install must never hold signing material. Catching it here
        // turns a catastrophic packaging mistake into a startup error.
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
            || $details['bits'] < self::MINIMUM_RSA_BITS) {
            throw new RuntimeException('The license verification public key is not an approved RSA key.');
        }

        return $key;
    }

    private function resolvePath(): string
    {
        $path = (string) config('medismart.licensing.public_key_path');

        if ($path === '') {
            throw new RuntimeException('No license verification public key is configured.');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = base_path($path);
        }

        return $path;
    }
}
