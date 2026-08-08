<?php

namespace App\Support;

use App\Models\HostedLicenseGrant;

/**
 * Ephemeral result returned to the issuer. It is deliberately not an Eloquent
 * attribute, so the plaintext code can never be persisted accidentally.
 */
final readonly class IssuedHostedLicenseCode
{
    public function __construct(
        public HostedLicenseGrant $grant,
        public string $code,
    ) {}
}
