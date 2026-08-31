<?php

namespace App\Services\Sync;

use App\Models\ApplicationSetting;

/**
 * Where this installation syncs with the mobile app, and the token it uses.
 *
 * The token is a Sanctum personal access token minted by
 * `POST /api/v1/auth/token` on the remote. It is a credential, so it is held in
 * `application_settings.encrypted_value` under this installation's own
 * `APP_KEY` and is never written to a log, a diagnostic bundle, or the
 * `last_error` column.
 */
final class MobileSyncSettings
{
    public const KEY_ENDPOINT = 'sync.mobile.endpoint';

    public const KEY_TOKEN = 'sync.mobile.token';

    public const KEY_ENABLED = 'sync.mobile.enabled';

    private const GROUP = 'sync';

    public function endpoint(): ?string
    {
        $endpoint = ApplicationSetting::valueFor(self::KEY_ENDPOINT);

        if (! is_string($endpoint) || trim($endpoint) === '') {
            return null;
        }

        return rtrim(trim($endpoint), '/');
    }

    public function token(): ?string
    {
        $token = ApplicationSetting::valueFor(self::KEY_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function enabled(): bool
    {
        return (bool) ApplicationSetting::valueFor(self::KEY_ENABLED, false);
    }

    /**
     * True when sync has everything it needs *and* the clinic has not turned it
     * off. `disable()` would otherwise have no effect on a run.
     */
    public function isConfigured(): bool
    {
        return $this->endpoint() !== null
            && $this->token() !== null
            && $this->enabled();
    }

    public function configure(string $endpoint, string $token): void
    {
        ApplicationSetting::putValue(
            self::KEY_ENDPOINT,
            rtrim(trim($endpoint), '/'),
            group: self::GROUP,
        );
        ApplicationSetting::putValue(
            self::KEY_TOKEN,
            $token,
            encrypted: true,
            group: self::GROUP,
        );
        ApplicationSetting::putValue(
            self::KEY_ENABLED,
            true,
            type: 'boolean',
            group: self::GROUP,
        );
    }

    public function disable(): void
    {
        ApplicationSetting::putValue(
            self::KEY_ENABLED,
            false,
            type: 'boolean',
            group: self::GROUP,
        );
    }
}
