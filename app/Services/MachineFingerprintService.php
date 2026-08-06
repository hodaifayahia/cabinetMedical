<?php

namespace App\Services;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\Device;
use Illuminate\Support\Str;
use RuntimeException;

final class MachineFingerprintService
{
    public function __construct(private readonly ApplicationSettingService $settings) {}

    public function installationId(): string
    {
        $nativeInstallationId = config('medismart.runtime.installation_id');

        if ((bool) config('medismart.runtime.desktop_supervised', false)) {
            if (! is_string($nativeInstallationId) || ! Str::isUuid($nativeInstallationId)) {
                throw new RuntimeException('The supervised desktop installation identity is missing or invalid.');
            }

            $stored = $this->settings->get(ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID);

            if ($stored !== $nativeInstallationId) {
                $this->settings->setInternal(
                    ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID,
                    $nativeInstallationId,
                );
            }

            return $nativeInstallationId;
        }

        $installationId = $this->settings->get(ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID);

        if (! is_string($installationId) || ! Str::isUuid($installationId)) {
            $installationId = (string) Str::uuid();
            $this->settings->setInternal(ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID, $installationId);
        }

        return $installationId;
    }

    public function fingerprintHash(): string
    {
        $seed = $this->settings->get(ApplicationSettingRegistry::DESKTOP_MACHINE_SEED);

        if (! is_string($seed) || strlen($seed) < 32) {
            $seed = bin2hex(random_bytes(32));
            $this->settings->setInternal(ApplicationSettingRegistry::DESKTOP_MACHINE_SEED, $seed);
        }

        $localSignals = implode('|', [
            PHP_OS_FAMILY,
            php_uname('m'),
            php_uname('n'),
            $seed,
        ]);
        $pepper = (string) config('medismart.licensing.fingerprint_pepper', config('app.key'));

        return hash_hmac('sha256', $localSignals, $pepper);
    }

    public function registerDevice(): Device
    {
        $installationId = $this->installationId();
        $device = Device::query()->firstOrNew(['installation_id' => $installationId]);

        $device->fill([
            'machine_fingerprint_hash' => $this->fingerprintHash(),
            'platform' => PHP_OS_FAMILY,
            'status' => 'active',
            'first_seen_at' => $device->first_seen_at ?? now(),
            'last_seen_at' => now(),
        ])->save();

        return $device;
    }
}
