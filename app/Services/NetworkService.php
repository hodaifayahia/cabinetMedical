<?php

namespace App\Services;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationSetting;
use JsonException;

final class NetworkService
{
    public function __construct(
        private readonly ApplicationSettingService $settings,
        private readonly LanUploadBoundary $lanUploadBoundary,
    ) {}

    /**
     * Return safe IPv4 candidates without invoking a shell. A supervised
     * desktop accepts only the bounded inventory written by the native runtime;
     * browser development retains the legacy hostname fallback.
     *
     * @return list<array{id: string, label: string, address: string, private: bool, source: string, index: int}>
     */
    public function ipv4Candidates(): array
    {
        if ((bool) config('medismart.runtime.desktop_supervised', false)) {
            return $this->nativeAdapterInventory();
        }

        $addresses = [];
        $manual = $this->settings->get(ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4)
            ?? ApplicationSetting::valueFor('network.manual_ipv4');

        if (is_string($manual)) {
            $addresses[] = ['address' => $manual, 'source' => 'manual'];
        }

        $hostname = gethostname();
        $resolved = is_string($hostname) ? gethostbynamel($hostname) : false;

        foreach ($resolved ?: [] as $address) {
            $addresses[] = ['address' => $address, 'source' => 'hostname'];
        }

        $candidates = [];

        foreach ($addresses as $candidate) {
            $address = $candidate['address'];

            if (! $this->isUsableIpv4($address)) {
                continue;
            }

            $candidates[$address] = [
                'id' => $address,
                'label' => ($candidate['source'] === 'manual' ? 'Adresse manuelle' : 'Interface détectée').' · '.$address,
                'address' => $address,
                'private' => $this->isPrivateIpv4($address),
                'source' => $candidate['source'],
                'index' => 0,
            ];
        }

        uasort($candidates, static fn (array $left, array $right): int => [
            ! $left['private'],
            $left['address'],
        ] <=> [
            ! $right['private'],
            $right['address'],
        ]);

        return array_values($candidates);
    }

    public function preferredIpv4(): ?string
    {
        $selectedAdapter = $this->selectedAdapterId();

        if ($selectedAdapter !== null) {
            foreach ($this->ipv4Candidates() as $candidate) {
                if ($candidate['id'] === $selectedAdapter && $candidate['private']) {
                    return $candidate['address'];
                }
            }
        }

        $selected = ApplicationSetting::valueFor('network.selected_ipv4');

        if (is_string($selected)
            && $this->isUsableIpv4($selected)
            && $this->isPrivateIpv4($selected)) {
            return $selected;
        }

        foreach ($this->ipv4Candidates() as $candidate) {
            if ($candidate['private']) {
                return $candidate['address'];
            }
        }

        return null;
    }

    public function selectedAdapterId(): ?string
    {
        $adapterId = $this->settings->get(ApplicationSettingRegistry::CONNECTIVITY_SELECTED_ADAPTER_ID);

        return is_string($adapterId) && $adapterId !== '' ? $adapterId : null;
    }

    public function localUploadBaseUrl(?int $port = null): ?string
    {
        if (! $this->lanListenerActive()) {
            return null;
        }

        $configuredOrigin = $this->lanUploadBoundary->configuredOrigin();
        $configuredValue = config('medismart.runtime.lan_upload_url');

        if ((bool) config('medismart.runtime.desktop_supervised', false)) {
            return $configuredOrigin;
        }

        if (is_string($configuredValue) && $configuredValue !== '') {
            return null;
        }

        $address = $this->preferredIpv4();

        if ($address === null) {
            return null;
        }

        $preferredPort = $this->settings->get(ApplicationSettingRegistry::CONNECTIVITY_PREFERRED_PORT);
        $port ??= is_int($preferredPort)
            ? $preferredPort
            : (int) ApplicationSetting::valueFor(
                'runtime.lan_port',
                config('medismart.runtime.lan_port', 8000),
            );

        if ($port < 1 || $port > 65535) {
            return null;
        }

        return "http://{$address}:{$port}";
    }

    public function lanListenerActive(): bool
    {
        return config('medismart.runtime.lan_listener_status') === 'active';
    }

    /**
     * @return list<array{id: string, label: string, address: string, private: true, source: 'native', index: int}>
     */
    private function nativeAdapterInventory(): array
    {
        $path = config('medismart.runtime.lan_adapters_file');

        if (! is_string($path) || $path === '' || is_link($path) || ! is_file($path)) {
            return [];
        }

        $size = @filesize($path);
        if (! is_int($size) || $size < 2 || $size > 64 * 1024) {
            return [];
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || strlen($contents) !== $size) {
            return [];
        }

        try {
            $inventory = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($inventory)
            || array_keys($inventory) !== ['schema_version', 'adapters']
            || ($inventory['schema_version'] ?? null) !== 1
            || ! is_array($inventory['adapters'])
            || count($inventory['adapters']) > 64) {
            return [];
        }

        $candidates = [];
        foreach ($inventory['adapters'] as $adapter) {
            if (! is_array($adapter)
                || array_keys($adapter) !== ['id', 'label', 'address', 'index']
                || ! is_string($adapter['id'])
                || preg_match('/\Aadapter-v1:[a-f0-9]{64}\z/D', $adapter['id']) !== 1
                || ! is_string($adapter['label'])
                || $adapter['label'] === ''
                || strlen($adapter['label']) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $adapter['label']) === 1
                || ! is_string($adapter['address'])
                || ! is_int($adapter['index'])
                || $adapter['index'] < 0
                || ! $this->isUsableIpv4($adapter['address'])
                || ! $this->isPrivateIpv4($adapter['address'])) {
                return [];
            }

            $key = $adapter['id'].'|'.$adapter['address'];
            $candidates[$key] = [
                'id' => $adapter['id'],
                'label' => $adapter['label'],
                'address' => $adapter['address'],
                'private' => true,
                'source' => 'native',
                'index' => $adapter['index'],
            ];
        }

        uasort($candidates, static fn (array $left, array $right): int => [
            $left['id'],
            $left['address'],
            $left['index'],
        ] <=> [
            $right['id'],
            $right['address'],
            $right['index'],
        ]);

        return array_values($candidates);
    }

    private function isUsableIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $octets = array_map('intval', explode('.', $address));

        return $octets[0] !== 127
            && ! ($octets[0] === 169 && $octets[1] === 254)
            && $address !== '0.0.0.0'
            && $address !== '255.255.255.255';
    }

    private function isPrivateIpv4(string $address): bool
    {
        $octets = array_map('intval', explode('.', $address));

        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }
}
