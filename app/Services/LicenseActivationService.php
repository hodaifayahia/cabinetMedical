<?php

namespace App\Services;

use App\Licensing\LicenseActivationProvider;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class LicenseActivationService
{
    public function __construct(
        private readonly LicenseActivationProvider $provider,
        private readonly LicenseService $licenses,
        private readonly MachineFingerprintService $fingerprint,
        private readonly LicenseClockService $clock,
    ) {}

    /**
     * @return array{
     *     configured: bool,
     *     refresh_configured: bool,
     *     deactivation_configured: bool,
     *     installation_id_hint: string,
     *     reason: string|null
     * }
     */
    public function status(): array
    {
        $installationId = $this->fingerprint->installationId();
        $verificationReady = $this->licenses->verificationReady();
        $configured = $this->provider->isConfigured() && $verificationReady;

        return [
            'configured' => $configured,
            'refresh_configured' => $this->provider->canRefresh() && $verificationReady,
            'deactivation_configured' => $this->provider->canDeactivate() && $verificationReady,
            'installation_id_hint' => substr($installationId, 0, 8).'…'.substr($installationId, -4),
            'reason' => $configured
                ? null
                : 'Configurez l’URL HTTPS du serveur de licences et sa clé publique de vérification.',
        ];
    }

    public function activate(#[SensitiveParameter] string $serial, User $actor): License
    {
        if (! $this->status()['configured']) {
            throw new RuntimeException('The license activation provider is unavailable.');
        }

        $normalized = $this->licenses->normalizeSerial($serial);

        if (! $this->licenses->hasValidSerialFormat($normalized)) {
            throw new RuntimeException('The license serial format is invalid.');
        }

        $installationId = $this->fingerprint->installationId();
        $machineFingerprintHash = $this->fingerprint->fingerprintHash();
        AuditLog::record('license.activation_requested', metadata: [
            'installation_id' => $installationId,
            'provider' => 'https',
        ], userId: $actor->getKey());

        try {
            $certificate = $this->provider->activate(
                $normalized,
                $installationId,
                $machineFingerprintHash,
                (string) config('medismart.version', 'unknown'),
            );
            $license = $this->licenses->activateFromCertificate($certificate);
        } catch (Throwable $exception) {
            AuditLog::record('license.activation_failed', metadata: [
                'installation_id' => $installationId,
                'error_type' => $exception::class,
            ], userId: $actor->getKey());
            $this->recordApplicationEvent('LicenseStatusChanged', 'warning', [
                'state' => 'activation_failed',
            ]);

            throw $exception;
        }

        $this->recordApplicationEvent('LicenseActivated', context: [
            'license_id' => $license->license_id,
            'edition' => $license->edition,
        ]);

        return $license;
    }

    public function refresh(?User $actor = null): License
    {
        if (! $this->provider->canRefresh() || ! $this->status()['refresh_configured']) {
            throw new RuntimeException('The license refresh provider is unavailable.');
        }

        $current = License::query()->latest('id')->first();

        if ($current === null) {
            throw new RuntimeException('No activated license is available to refresh.');
        }

        $installationId = $this->fingerprint->installationId();
        $machineFingerprintHash = $this->fingerprint->fingerprintHash();
        AuditLog::record('license.refresh_requested', $current, [
            'installation_id' => $installationId,
            'provider' => 'https',
        ], $actor?->getKey());

        try {
            $payload = $this->licenses->verifyCertificateForCurrentInstallation(
                $current->signed_certificate,
            );

            if (! hash_equals($current->license_id, (string) $payload['license_id'])) {
                throw new RuntimeException('The stored license does not match its signed certificate.');
            }

            $certificate = $this->provider->refresh(
                $current->license_id,
                $installationId,
                $machineFingerprintHash,
                $current->signed_certificate,
                (string) config('medismart.version', 'unknown'),
            );
            $license = $this->licenses->refreshFromCertificate($certificate, $current);
        } catch (Throwable $exception) {
            AuditLog::record('license.refresh_failed', $current, [
                'installation_id' => $installationId,
                'error_type' => $exception::class,
            ], $actor?->getKey());

            throw $exception;
        }

        $this->recordApplicationEvent('LicenseStatusChanged', context: [
            'license_id' => $license->license_id,
            'state' => $this->licenses->status()['state'],
        ]);

        return $license;
    }

    public function deactivate(?User $actor = null): void
    {
        if (! $this->provider->canDeactivate() || ! $this->status()['deactivation_configured']) {
            throw new RuntimeException('The license deactivation provider is unavailable.');
        }

        $current = License::query()->latest('id')->first();

        if ($current === null) {
            throw new RuntimeException('No activated license is available to deactivate.');
        }

        $installationId = $this->fingerprint->installationId();
        $machineFingerprintHash = $this->fingerprint->fingerprintHash();
        AuditLog::record('license.deactivation_requested', $current, [
            'installation_id' => $installationId,
            'provider' => 'https',
        ], $actor?->getKey());

        try {
            $payload = $this->licenses->verifyCertificateForCurrentInstallation(
                $current->signed_certificate,
            );

            if (! hash_equals($current->license_id, (string) $payload['license_id'])) {
                throw new RuntimeException('The stored license does not match its signed certificate.');
            }

            $this->provider->deactivate(
                $current->license_id,
                $installationId,
                $machineFingerprintHash,
                $current->signed_certificate,
                (string) config('medismart.version', 'unknown'),
            );

            DB::transaction(function () use ($current, $installationId, $actor): void {
                AuditLog::record('license.deactivated', $current, [
                    'license_id' => $current->license_id,
                    'installation_id' => $installationId,
                ], $actor?->getKey());
                $current->delete();
                $this->clock->clear();
            });

        } catch (Throwable $exception) {
            AuditLog::record('license.deactivation_failed', $current, [
                'installation_id' => $installationId,
                'error_type' => $exception::class,
            ], $actor?->getKey());

            throw $exception;
        }

        $this->recordApplicationEvent('LicenseStatusChanged', context: [
            'state' => 'not_activated',
        ]);
    }

    /** @param array<string, mixed> $context */
    private function recordApplicationEvent(
        string $event,
        string $severity = 'info',
        array $context = [],
    ): void {
        try {
            ApplicationEvent::record($event, $severity, context: $context);
        } catch (Throwable) {
            // Event history is diagnostic. It must never reverse or
            // misreport an already committed licensing transition.
        }
    }
}
