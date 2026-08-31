<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * Verifies a signed cabinet entitlement with no network access at all.
 *
 * A cabinet on a LAN Hub cannot ask the hosted control plane whether it is
 * allowed to run — that is the whole point of the Hub. The platform therefore
 * signs an entitlement naming the cabinet, the plan and the expiry; the Hub
 * checks the signature against the same RSA public key it already ships for
 * desktop licences and needs nothing else.
 *
 * The envelope matches the existing licence certificate so both can be carried,
 * stored, and audited the same way:
 *
 *   {"algorithm":"RS256","payload":"<base64url>","signature":"<base64url>"}
 */
final class CabinetEntitlementVerifier
{
    private const MAXIMUM_ENVELOPE_BYTES = 131_072;

    private const SUPPORTED_VERSION = 1;

    /** @var list<string> */
    private const REQUIRED_CLAIMS = ['entitlement_id', 'product', 'owner_email', 'plan', 'issued_at'];

    public function __construct(private readonly VerificationKey $key) {}

    public function isConfigured(): bool
    {
        return $this->key->isConfigured();
    }

    public function verify(#[SensitiveParameter] string $envelope): CabinetEntitlement
    {
        $payload = $this->decodeVerifiedPayload($envelope);

        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (! is_string($payload[$claim] ?? null) || $payload[$claim] === '') {
                throw new RuntimeException("The signed entitlement is missing {$claim}.");
            }
        }

        $version = $payload['entitlement_version'] ?? null;

        if (! is_int($version) || $version < 1) {
            throw new RuntimeException('The signed entitlement version is invalid.');
        }

        // Refuse rather than guess at a newer format: an entitlement this build
        // cannot fully understand might carry a restriction it would ignore.
        if ($version > self::SUPPORTED_VERSION) {
            throw new RuntimeException('This entitlement was issued for a newer version of Drclick.');
        }

        if (! hash_equals((string) config('medismart.licensing.product'), $payload['product'])) {
            throw new RuntimeException('The entitlement was issued for another product.');
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $payload['entitlement_id']) !== 1) {
            throw new RuntimeException('The signed entitlement identifier is invalid.');
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $payload['plan']) !== 1) {
            throw new RuntimeException('The signed entitlement plan is invalid.');
        }

        $ownerEmail = Str::lower(trim($payload['owner_email']));

        if (strlen($ownerEmail) > 190 || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The signed entitlement owner address is invalid.');
        }

        $hubId = $payload['hub_id'] ?? null;

        if ($hubId !== null && (! is_string($hubId) || $hubId === '' || strlen($hubId) > 190)) {
            throw new RuntimeException('The signed entitlement hub identifier is invalid.');
        }

        $issuedAt = $this->timestamp($payload['issued_at'], 'issued_at');
        $expiresAt = ($payload['expires_at'] ?? null) === null
            ? null
            : $this->timestamp($payload['expires_at'], 'expires_at');

        if ($expiresAt !== null && $expiresAt->isBefore($issuedAt)) {
            throw new RuntimeException('The signed entitlement expires before it was issued.');
        }

        return new CabinetEntitlement(
            entitlementId: $payload['entitlement_id'],
            ownerEmail: $ownerEmail,
            plan: $payload['plan'],
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            hubId: $hubId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeVerifiedPayload(#[SensitiveParameter] string $envelope): array
    {
        if ($envelope === '' || strlen($envelope) > self::MAXIMUM_ENVELOPE_BYTES) {
            throw new RuntimeException('The entitlement envelope is invalid.');
        }

        try {
            $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The entitlement is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)
            || ($decoded['algorithm'] ?? null) !== 'RS256'
            || ! is_string($decoded['payload'] ?? null)
            || ! is_string($decoded['signature'] ?? null)) {
            throw new RuntimeException('The entitlement envelope is invalid.');
        }

        // The signature covers the encoded payload segment verbatim, so the
        // bytes that are verified are exactly the bytes that are decoded.
        $verified = openssl_verify(
            $decoded['payload'],
            $this->base64UrlDecode($decoded['signature']),
            $this->key->load(),
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new RuntimeException('The entitlement signature is invalid.');
        }

        try {
            $payload = json_decode($this->base64UrlDecode($decoded['payload']), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The signed entitlement payload is invalid.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('The signed entitlement payload is invalid.');
        }

        return $payload;
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The signed entitlement {$field} is invalid.");
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException $exception) {
            throw new RuntimeException("The signed entitlement {$field} is invalid.", previous: $exception);
        }
    }

    private function base64UrlDecode(#[SensitiveParameter] string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('The entitlement envelope is not correctly encoded.');
        }

        return $decoded;
    }
}
