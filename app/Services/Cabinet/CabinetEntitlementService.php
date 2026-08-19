<?php

namespace App\Services\Cabinet;

use App\Models\License;
use App\Models\User;
use App\Services\LicenseService;
use Carbon\CarbonImmutable;

/**
 * Resolves feature flags without mixing centrally hosted cabinet plans with
 * the signed, machine-bound desktop certificate. Hosted plans intentionally
 * enable only SaaS-safe features; native connectivity/backup/update features
 * continue to require the legacy signed entitlement.
 */
final class CabinetEntitlementService
{
    /** @var list<string> */
    private const HOSTED_FEATURES = [
        'custom_branding',
        'multi_user',
    ];

    public function __construct(
        private readonly LicenseService $localLicenses,
    ) {}

    public function featureEnabled(?User $user, string $feature): bool
    {
        $hosted = $this->hostedEntitlement($user);

        if ($hosted !== null) {
            return $user?->cabinet?->isActive() === true
                && $hosted->effectiveStatus() === 'active'
                && in_array($feature, self::HOSTED_FEATURES, true);
        }

        // Legacy unscoped installs and cabinets backfilled without a hosted
        // entitlement retain the signed-certificate behaviour.
        return $this->localLicenses->featureEnabled($feature);
    }

    public function hostedEntitlement(?User $user): ?License
    {
        if ($user === null || $user->is_platform_admin) {
            return null;
        }

        $license = $user->cabinet?->license;

        return $license?->isHostedEntitlement() === true ? $license : null;
    }

    public function remainingDays(?License $license): ?int
    {
        if ($license === null || $license->expires_at === null) {
            return null;
        }

        return max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds($license->expires_at, false) / 86400));
    }
}
