<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TunnelSetting;
use Carbon\CarbonImmutable;

final class TunnelService
{
    public function __construct(private readonly NativeTunnelStatusVerifier $nativeTunnelStatusVerifier) {}

    /**
     * @return array<string, bool|string|null>
     */
    public function status(): array
    {
        $settings = $this->settings();

        if ($settings === null) {
            return [
                'configured' => false,
                'provider' => 'cloudflare',
                'mode' => 'named',
                'hostname' => null,
                'service_installed' => false,
                'cloudflared_version' => null,
                'retry_count' => null,
                'desired_state' => 'stopped',
                'runtime_state' => 'stopped',
                'last_health_check_at' => null,
                'last_error' => null,
            ];
        }

        $configured = filled($settings->hostname) && filled($settings->encrypted_tunnel_token);
        if (! $configured) {
            return [
                'configured' => false,
                'provider' => $settings->provider,
                'mode' => $settings->mode,
                'hostname' => $settings->hostname,
                'service_installed' => false,
                'cloudflared_version' => null,
                'retry_count' => null,
                'desired_state' => $settings->desired_state,
                'runtime_state' => 'stopped',
                'last_health_check_at' => null,
                'last_error' => 'tunnel_configuration_incomplete',
            ];
        }

        try {
            $nativeStatus = $this->nativeTunnelStatusVerifier->verify($settings->hostname);
        } catch (NativeTunnelStatusException $exception) {
            return [
                'configured' => true,
                'provider' => $settings->provider,
                'mode' => $settings->mode,
                'hostname' => $settings->hostname,
                'service_installed' => false,
                'cloudflared_version' => null,
                'retry_count' => null,
                'desired_state' => $settings->desired_state,
                'runtime_state' => 'unavailable',
                'last_health_check_at' => null,
                'last_error' => $exception->reason,
            ];
        }

        return [
            'configured' => true,
            'provider' => $settings->provider,
            'mode' => $settings->mode,
            'hostname' => $settings->hostname,
            'service_installed' => $nativeStatus->executableVerified,
            'cloudflared_version' => $nativeStatus->cloudflaredVersion,
            'retry_count' => (string) $nativeStatus->retryCount,
            'desired_state' => $settings->desired_state,
            'runtime_state' => $nativeStatus->phase === 'ready' ? 'active' : $nativeStatus->phase,
            'last_health_check_at' => CarbonImmutable::createFromTimestampMs(
                $nativeStatus->updatedAtUnixMs,
                'UTC',
            )->toIso8601String(),
            'last_error' => $nativeStatus->lastErrorCode,
        ];
    }

    public function storeToken(TunnelSetting $settings, string $token): void
    {
        $settings->update(['encrypted_tunnel_token' => $token]);
    }

    public function redact(string $text): string
    {
        $storedToken = $this->settings()?->encrypted_tunnel_token;

        if (is_string($storedToken) && $storedToken !== '') {
            $text = str_replace($storedToken, '[redacted]', $text);
        }

        $patterns = [
            '/(--token(?:=|\s+))[^\s]+/i' => '$1[redacted]',
            '/("(?:token|secret)"\s*:\s*")[^"]+("?)/i' => '$1[redacted]$2',
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i' => '$1[redacted]',
        ];

        $redacted = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);

        return AuditLog::redactSensitiveText($redacted);
    }

    private function settings(): ?TunnelSetting
    {
        return TunnelSetting::query()
            ->where('provider', 'cloudflare')
            ->where('mode', 'named')
            ->first();
    }
}
