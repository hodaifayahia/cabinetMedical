<?php

namespace App\Licensing;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Online-service half of desktop activation: turns an activation code, or
 * the cabinet owner's online account, into a signed entitlement bound to one
 * installation.
 *
 * The desktop verifies that entitlement against the public key it ships and
 * then never needs the online service again for daily use — which is the
 * whole point: a clinic must keep working when the website is down.
 */
final class DesktopEntitlementGrantor
{
    public function __construct(
        private readonly CabinetEntitlementIssuer $issuer,
        private readonly CabinetFulfillmentService $fulfillment,
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * Only the online service holds the signing key; a Hub or a desktop never
     * does, so on them this is always false.
     */
    public function isAvailable(): bool
    {
        if (config('hub.enabled', false)) {
            return false;
        }

        $path = $this->signingKeyPath();

        return $path !== null && is_file($path) && is_readable($path);
    }

    /**
     * @return array{entitlement: string, cabinet: Cabinet, license: License}
     *
     * @throws DesktopActivationRefused
     */
    public function activateWithCode(
        #[SensitiveParameter] string $code,
        string $installationId,
        string $ownerEmail,
    ): array {
        // Checked before the grant is touched: a code must never be consumed
        // when no entitlement can be produced for it.
        if (! $this->isAvailable()) {
            throw DesktopActivationRefused::signingUnavailable();
        }

        $redeemed = $this->fulfillment->redeemLicenseCodeForInstallation($code, $installationId, $ownerEmail);

        return [
            'entitlement' => $this->sign($ownerEmail, $redeemed['license'], $installationId, $redeemed['cabinet']),
            'cabinet' => $redeemed['cabinet'],
            'license' => $redeemed['license'],
        ];
    }

    /**
     * An existing online cabinet brought to an installed desktop: the owner
     * signs in with their online account, whose cabinet must already be
     * active and licensed here.
     *
     * @return array{entitlement: string, cabinet: Cabinet, license: License|null, owner: User}
     *
     * @throws DesktopActivationRefused
     */
    public function activateWithAccount(
        string $email,
        #[SensitiveParameter] string $password,
        string $installationId,
    ): array {
        if (! $this->isAvailable()) {
            throw DesktopActivationRefused::signingUnavailable();
        }

        $user = $this->userByEmail($email);

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            throw DesktopActivationRefused::invalidCredentials();
        }

        $cabinet = $user->cabinet;

        if ($user->is_platform_admin
            || ! $cabinet instanceof Cabinet
            || $cabinet->owner_user_id !== $user->getKey()) {
            throw DesktopActivationRefused::notOwner();
        }

        if ($this->access->denialReason($user) !== null) {
            throw DesktopActivationRefused::accessDenied(
                (string) ($this->access->denialMessage($user) ?? 'Ce cabinet ne peut pas être activé pour le moment.'),
            );
        }

        $license = $cabinet->license;

        return [
            'entitlement' => $this->sign((string) $user->email, $license, $installationId, $cabinet),
            'cabinet' => $cabinet,
            'license' => $license,
            'owner' => $user,
        ];
    }

    /**
     * The entitlement mirrors the licence the online cabinet holds now: a
     * lifetime licence stays lifetime, a trial keeps its exact expiry.
     */
    private function sign(string $ownerEmail, ?License $license, string $installationId, Cabinet $cabinet): string
    {
        $expiresAt = $license?->expires_at === null ? null : CarbonImmutable::instance($license->expires_at);
        $plan = $expiresAt === null ? 'lifetime' : 'trial';

        try {
            $envelope = $this->issuer->issue(
                signingKeyPath: (string) $this->signingKeyPath(),
                ownerEmail: $ownerEmail,
                plan: $plan,
                hubId: $installationId,
                passphrase: $this->passphrase(),
                expiresAt: $expiresAt,
            );
        } catch (Throwable $exception) {
            report($exception);

            throw DesktopActivationRefused::signingUnavailable();
        }

        AuditLog::record('cabinet.desktop_entitlement_issued', $cabinet, [
            'installation_id' => $installationId,
            'license_id' => $license?->license_id,
            'plan' => $plan,
            'expires_at' => $expiresAt?->toIso8601String(),
            'owner_email' => Str::lower(trim($ownerEmail)),
        ]);

        return $envelope;
    }

    /**
     * Exact address first; otherwise a case-insensitive match, but only when
     * it is unambiguous.
     */
    private function userByEmail(string $email): ?User
    {
        $email = trim($email);
        $exact = User::query()->where('email', $email)->first();

        if ($exact instanceof User) {
            return $exact;
        }

        $matches = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function signingKeyPath(): ?string
    {
        $path = config('medismart.licensing.entitlement_signing_key_path');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = base_path($path);
        }

        return $path;
    }

    private function passphrase(): ?string
    {
        $passphrase = config('medismart.licensing.entitlement_signing_key_passphrase');

        return is_string($passphrase) && $passphrase !== '' ? $passphrase : null;
    }
}
