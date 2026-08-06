<?php

namespace App\Services;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationSetting;
use Carbon\CarbonImmutable;
use Throwable;

final class LicenseClockService
{
    public function __construct(private readonly ApplicationSettingService $settings) {}

    /**
     * Compare local time with an encrypted, installation-local high-water mark.
     * A newly signed certificate can reset the mark through recordServerTime(),
     * so correcting a bad local clock never permanently blocks the cabinet.
     *
     * @return array{
     *     effective_now: CarbonImmutable,
     *     rollback_detected: bool,
     *     trusted_at: CarbonImmutable|null
     * }
     */
    public function evaluate(CarbonImmutable $signedIssuedAt): array
    {
        $localNow = CarbonImmutable::now();
        $storedRowExists = ApplicationSetting::query()
            ->where('key', Setting::LICENSING_TRUSTED_TIME)
            ->exists();
        // persistCertificate() creates the encrypted anchor before a license
        // becomes effective. A license without its anchor therefore indicates
        // deletion, a partial restore, or corruption and must not reset trust.
        $integrityFailure = ! $storedRowExists;
        $trustedAt = null;

        try {
            $stored = $this->settings->get(Setting::LICENSING_TRUSTED_TIME);

            if (is_string($stored) && $stored !== '') {
                $trustedAt = CarbonImmutable::parse($stored);
            } elseif ($storedRowExists) {
                // An encrypted row that no longer resolves must fail closed.
                $integrityFailure = true;
            }
        } catch (Throwable) {
            $integrityFailure = true;
        }

        if (! $integrityFailure && ($trustedAt === null || $signedIssuedAt->isAfter($trustedAt))) {
            $trustedAt = $signedIssuedAt;

            try {
                $this->persist($trustedAt);
            } catch (Throwable) {
                $integrityFailure = true;
            }
        }

        $effectiveNow = $trustedAt !== null && $trustedAt->isAfter($localNow)
            ? $trustedAt
            : $localNow;
        $toleranceHours = max(1, min(
            168,
            (int) config('medismart.licensing.clock_rollback_tolerance_hours', 6),
        ));
        $rollbackDetected = $integrityFailure
            || ($trustedAt !== null && $localNow->isBefore($trustedAt->subHours($toleranceHours)));

        if (! $rollbackDetected
            && $trustedAt !== null
            && $localNow->isAfter($trustedAt->addMinutes(15))) {
            try {
                $this->persist($localNow);
                $trustedAt = $localNow;
            } catch (Throwable) {
                $rollbackDetected = true;
            }
        }

        return [
            'effective_now' => $effectiveNow,
            'rollback_detected' => $rollbackDetected,
            'trusted_at' => $trustedAt,
        ];
    }

    /** Reset the local high-water mark from authenticated certificate time. */
    public function recordServerTime(CarbonImmutable $serverTime): void
    {
        $this->persist($serverTime);
    }

    public function clear(): void
    {
        $this->settings->setInternal(Setting::LICENSING_TRUSTED_TIME, null);
    }

    private function persist(CarbonImmutable $time): void
    {
        $this->settings->setInternal(
            Setting::LICENSING_TRUSTED_TIME,
            $time->utc()->toIso8601String(),
        );
    }
}
