<?php

namespace App\Licensing;

use SensitiveParameter;

interface LicenseActivationProvider
{
    public function isConfigured(): bool;

    public function canRefresh(): bool;

    public function canDeactivate(): bool;

    public function activate(
        #[SensitiveParameter] string $serial,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        string $applicationVersion,
    ): string;

    public function refresh(
        string $licenseId,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        #[SensitiveParameter] string $currentCertificate,
        string $applicationVersion,
    ): string;

    public function deactivate(
        string $licenseId,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        #[SensitiveParameter] string $currentCertificate,
        string $applicationVersion,
    ): void;
}
