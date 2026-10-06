<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use RuntimeException;
use SensitiveParameter;

/**
 * Signs cabinet entitlements. This is the control plane's half of the offline
 * activation story, and the only code in the repository that touches private
 * key material.
 *
 * CabinetEntitlementVerifier proves an entitlement is authentic; nothing until
 * now could produce one, so `hub:activate` had no input it could ever be given
 * outside a test. That asymmetry is deliberate in the client
 * (VerificationKey refuses to load a private key at all), so the issuer takes
 * its key from an explicit path the operator names — never from the licensing
 * configuration a client install ships.
 *
 * The guard that matters most: a Hub must never be able to mint its own
 * entitlement. If it could, offline activation would be self-service and the
 * licence would mean nothing.
 */
final class CabinetEntitlementIssuer
{
    private const MINIMUM_RSA_BITS = 2048;

    /** @var list<string> */
    public const PLANS = ['trial', 'lifetime'];

    /**
     * @param  positive-int|null  $trialDays  Days a trial runs for; ignored for lifetime.
     * @param  CarbonImmutable|null  $expiresAt  An exact trial expiry, taking precedence over
     *                                           $trialDays: used when the entitlement mirrors a
     *                                           licence the online service already holds.
     */
    public function issue(
        string $signingKeyPath,
        string $ownerEmail,
        string $plan,
        ?int $trialDays = null,
        ?string $hubId = null,
        ?CarbonImmutable $issuedAt = null,
        #[SensitiveParameter] ?string $passphrase = null,
        ?CarbonImmutable $expiresAt = null,
    ): string {
        $this->assertNotOnAHub();

        if (! in_array($plan, self::PLANS, true)) {
            throw new RuntimeException('Unknown plan "'.$plan.'". Use one of: '.implode(', ', self::PLANS).'.');
        }

        $ownerEmail = Str::lower(trim($ownerEmail));

        if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The cabinet owner address is not a valid e-mail address.');
        }

        $issuedAt ??= CarbonImmutable::now()->utc();
        $expiresAt = $plan === 'lifetime'
            ? null
            : ($expiresAt?->utc() ?? $issuedAt->addDays($trialDays ?? 7));

        if ($expiresAt !== null && $expiresAt->isBefore($issuedAt)) {
            throw new RuntimeException('An entitlement cannot expire before it is issued.');
        }

        $payload = [
            'entitlement_version' => 1,
            'entitlement_id' => 'ENT-'.Str::upper(Str::random(20)),
            'product' => (string) config('medismart.licensing.product'),
            'owner_email' => $ownerEmail,
            'plan' => $plan,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
        ];

        // An absent hub_id means "any Hub belonging to this customer". Only
        // include the claim when the operator actually bound it, so the
        // verifier's optional-claim contract is preserved.
        if ($hubId !== null && trim($hubId) !== '') {
            $payload['hub_id'] = trim($hubId);
        }

        return $this->sign($payload, $this->loadPrivateKey($signingKeyPath, $passphrase));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload, OpenSSLAsymmetricKey $key): string
    {
        // The signature covers the encoded payload segment verbatim, exactly as
        // CabinetEntitlementVerifier checks it. Signing the decoded JSON instead
        // would verify only by accident, and only until a re-encode changed a
        // single byte of whitespace or key order.
        $segment = $this->base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        if (openssl_sign($segment, $signature, $key, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('The entitlement could not be signed.');
        }

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $segment,
            'signature' => $this->base64UrlEncode($signature),
        ], JSON_THROW_ON_ERROR) ?: throw new RuntimeException('The entitlement envelope could not be encoded.');
    }

    private function loadPrivateKey(
        string $path,
        #[SensitiveParameter] ?string $passphrase,
    ): OpenSSLAsymmetricKey {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('No readable signing key at '.$path.'.');
        }

        $contents = (string) file_get_contents($path);
        $key = openssl_pkey_get_private($contents, $passphrase);

        if ($key === false) {
            throw new RuntimeException('The signing key could not be loaded. It must be an unencrypted PEM private key, or a passphrase must be supplied.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ! is_int($details['bits'] ?? null)
            || $details['bits'] < self::MINIMUM_RSA_BITS) {
            throw new RuntimeException('The signing key is not an approved RSA key of at least '.self::MINIMUM_RSA_BITS.' bits.');
        }

        return $key;
    }

    /**
     * A Cabinet Hub is a cabinet's data plane. Letting one sign its own
     * entitlement would turn the licence into a self-issued formality, so the
     * issuer refuses to run there whatever key it is handed.
     */
    private function assertNotOnAHub(): void
    {
        if (config('hub.enabled', false)) {
            throw new RuntimeException('A Cabinet Hub must never issue its own entitlements. Run this on the control plane.');
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
