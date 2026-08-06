<?php

namespace App\Licensing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use RuntimeException;
use SensitiveParameter;

final class HttpLicenseActivationProvider implements LicenseActivationProvider
{
    public function isConfigured(): bool
    {
        return $this->configuredUrl('activation_url') !== null;
    }

    public function canRefresh(): bool
    {
        return $this->configuredUrl('status_url') !== null;
    }

    public function canDeactivate(): bool
    {
        return $this->configuredUrl('deactivation_url') !== null;
    }

    public function activate(
        #[SensitiveParameter] string $serial,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        string $applicationVersion,
    ): string {
        $url = $this->configuredUrl('activation_url');

        if ($url === null) {
            throw new RuntimeException('The license activation provider is unavailable.');
        }

        $response = $this->post($url, [
            'serial' => $serial,
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'application_version' => $applicationVersion,
            'product' => (string) config('medismart.licensing.product'),
        ], 'activate');

        return $this->certificateFrom($response, 'activation');
    }

    public function refresh(
        string $licenseId,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        #[SensitiveParameter] string $currentCertificate,
        string $applicationVersion,
    ): string {
        $url = $this->configuredUrl('status_url');

        if ($url === null) {
            throw new RuntimeException('The license refresh provider is unavailable.');
        }

        $response = $this->post($url, $this->certificatePayload(
            $licenseId,
            $installationId,
            $machineFingerprintHash,
            $currentCertificate,
            $applicationVersion,
        ), 'refresh');

        return $this->certificateFrom($response, 'refresh');
    }

    public function deactivate(
        string $licenseId,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        #[SensitiveParameter] string $currentCertificate,
        string $applicationVersion,
    ): void {
        $url = $this->configuredUrl('deactivation_url');

        if ($url === null) {
            throw new RuntimeException('The license deactivation provider is unavailable.');
        }

        $response = $this->post($url, $this->certificatePayload(
            $licenseId,
            $installationId,
            $machineFingerprintHash,
            $currentCertificate,
            $applicationVersion,
        ), 'deactivate');

        if (! $response->successful()) {
            throw new RuntimeException('The license server rejected the deactivation request.');
        }
    }

    /** @param array<string, string> $payload */
    private function post(
        string $url,
        #[SensitiveParameter] array $payload,
        string $operation,
    ): Response {
        $idempotencyKey = (string) Str::uuid();
        $send = fn (): Response => Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
                'X-MediSmart-License-Operation' => $operation,
                'X-MediSmart-License-Protocol' => '1',
            ])
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting()
            ->retry(2, 250, throw: false)
            ->post($url, $payload);

        try {
            $response = class_exists(Telescope::class)
                ? Telescope::withoutRecording($send)
                : $send();

            if (! $response instanceof Response) {
                throw new RuntimeException('The license server returned an invalid response.');
            }

            return $response;
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'The license server is unavailable.',
                previous: $exception,
            );
        }
    }

    private function certificateFrom(Response $response, string $operation): string
    {
        if (! $response->successful()) {
            throw new RuntimeException("The license server rejected the {$operation} request.");
        }

        $certificate = $response->json('license_certificate');

        if (! is_string($certificate) || $certificate === '') {
            throw new RuntimeException("The license server rejected the {$operation} request.");
        }

        return $certificate;
    }

    /** @return array<string, string> */
    private function certificatePayload(
        string $licenseId,
        string $installationId,
        #[SensitiveParameter] string $machineFingerprintHash,
        #[SensitiveParameter] string $currentCertificate,
        string $applicationVersion,
    ): array {
        return [
            'license_id' => $licenseId,
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'license_certificate' => $currentCertificate,
            'application_version' => $applicationVersion,
            'product' => (string) config('medismart.licensing.product'),
        ];
    }

    private function configuredUrl(string $key): ?string
    {
        $url = config("medismart.licensing.{$key}");

        if (! is_string($url)
            || $url === ''
            || trim($url) !== $url
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }

        return $url;
    }
}
