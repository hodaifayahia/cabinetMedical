<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * A verified, cabinet-bound entitlement.
 *
 * ADR-002 invariant 8: "The Hub receives a signed, cabinet-bound entitlement
 * and may cache it for verified offline use." This is that entitlement once
 * its signature has been checked — it exists only if the platform signed it.
 */
final class CabinetEntitlement
{
    public function __construct(
        public readonly string $entitlementId,
        public readonly string $ownerEmail,
        public readonly string $plan,
        public readonly CarbonImmutable $issuedAt,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $hubId,
    ) {}

    /**
     * A lifetime entitlement never expires; a trial carries an expiry.
     */
    public function isLifetime(): bool
    {
        return $this->expiresAt === null;
    }

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt->isBefore($now ?? CarbonImmutable::now());
    }

    /**
     * Whether this entitlement was issued for a particular Hub. An entitlement
     * with no hub_id is portable across the customer's own machines; one that
     * names a Hub is only valid on it.
     */
    public function boundToHub(?string $hubId): bool
    {
        if ($this->hubId === null) {
            return true;
        }

        return $hubId !== null && hash_equals($this->hubId, $hubId);
    }

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return [
            'entitlement_id' => $this->entitlementId,
            'plan' => $this->plan,
            'issued_at' => $this->issuedAt->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'hub_id' => $this->hubId,
        ];
    }
}
