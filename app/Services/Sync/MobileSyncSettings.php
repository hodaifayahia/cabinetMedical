<?php

namespace App\Services\Sync;

use App\Models\ApplicationSetting;
use Carbon\CarbonImmutable;

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

    /** Shown on Configuration › Service en ligne; never used to authenticate. */
    public const KEY_ACCOUNT_EMAIL = 'sync.mobile.account_email';

    public const KEY_CABINET_NAME = 'sync.mobile.cabinet_name';

    public const KEY_LINKED_AT = 'sync.mobile.linked_at';

    /**
     * The local cabinet the link was verified for. The token belongs to that
     * cabinet's online account, so no other cabinet held on this computer may
     * sync, check seats or spend AI credits with it.
     */
    public const KEY_CABINET_ID = 'sync.mobile.cabinet_id';

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

    public function accountEmail(): ?string
    {
        return $this->stringValue(self::KEY_ACCOUNT_EMAIL);
    }

    public function cabinetName(): ?string
    {
        return $this->stringValue(self::KEY_CABINET_NAME);
    }

    public function linkedAt(): ?CarbonImmutable
    {
        $linkedAt = $this->stringValue(self::KEY_LINKED_AT);

        return $linkedAt === null ? null : CarbonImmutable::parse($linkedAt);
    }

    /**
     * The local cabinet this link serves, or null for a link recorded without
     * one, which serves the whole installation.
     */
    public function cabinetId(): ?int
    {
        $cabinetId = ApplicationSetting::valueFor(self::KEY_CABINET_ID);

        return is_int($cabinetId) && $cabinetId > 0 ? $cabinetId : null;
    }

    /**
     * Whether a user of this local cabinet may use the link.
     */
    public function servesCabinet(int|string|null $cabinetId): bool
    {
        $linked = $this->cabinetId();

        return $linked === null || ($cabinetId !== null && (int) $cabinetId === $linked);
    }

    public function configure(
        string $endpoint,
        string $token,
        ?string $accountEmail = null,
        ?string $cabinetName = null,
        ?int $cabinetId = null,
    ): void {
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
        ApplicationSetting::putValue(self::KEY_ACCOUNT_EMAIL, $accountEmail ?? '', group: self::GROUP);
        ApplicationSetting::putValue(self::KEY_CABINET_NAME, $cabinetName ?? '', group: self::GROUP);
        ApplicationSetting::putValue(self::KEY_LINKED_AT, now()->toIso8601String(), group: self::GROUP);

        if ($cabinetId === null) {
            ApplicationSetting::query()->where('key', self::KEY_CABINET_ID)->delete();
        } else {
            ApplicationSetting::putValue(self::KEY_CABINET_ID, $cabinetId, type: 'integer', group: self::GROUP);
        }
    }

    /**
     * Drop the credential and everything describing the link. The endpoint is
     * kept so linking again starts from the address already used.
     */
    public function forget(): void
    {
        ApplicationSetting::query()
            ->whereIn('key', [self::KEY_TOKEN, self::KEY_ACCOUNT_EMAIL, self::KEY_CABINET_NAME, self::KEY_LINKED_AT, self::KEY_CABINET_ID])
            ->delete();

        $this->disable();
    }

    private function stringValue(string $key): ?string
    {
        $value = ApplicationSetting::valueFor($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
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
