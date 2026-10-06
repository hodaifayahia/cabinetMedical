<?php

namespace App\Licensing;

use SensitiveParameter;

/**
 * A verified answer from the online service's desktop activation call.
 */
final class CloudActivation
{
    /**
     * @param  array<string, mixed>  $cabinet  The online cabinet's identity (name, specialty,
     *                                         wilaya, phone, owner), never clinical data.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $envelope,
        public readonly CabinetEntitlement $entitlement,
        public readonly array $cabinet,
        #[SensitiveParameter] public readonly ?string $token = null,
        public readonly ?string $accountEmail = null,
        public readonly ?string $accountCabinetName = null,
    ) {}
}
