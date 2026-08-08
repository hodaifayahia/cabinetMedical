<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\GoogleDriveOAuthAttempt;
use App\Models\User;
use App\Services\Backups\GoogleDriveBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GoogleDriveOAuthFlow
{
    private const ATTEMPT_TTL_MINUTES = 10;

    public function __construct(
        private readonly GoogleDriveBackup $drive,
        private readonly GoogleOAuthLoopbackOrigin $origin,
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
    ) {}

    public function available(): bool
    {
        return $this->drive->isConfigured() && $this->origin->available();
    }

    /** @return array{authorization_url: string} */
    public function prepare(CabinetSetting $cabinet, User $actor): array
    {
        if (! $this->installationMaintenance->allows($actor)) {
            throw new GoogleDriveOAuthException('installation_maintenance_forbidden');
        }

        if (! $this->available()) {
            throw new GoogleDriveOAuthException('oauth_configuration_unavailable');
        }

        if ($actor->cabinet_setting_id !== null
            && (int) $actor->cabinet_setting_id !== (int) $cabinet->getKey()) {
            throw new GoogleDriveOAuthException('actor_cabinet_mismatch');
        }

        $state = $this->randomBase64Url(32);
        $verifier = $this->randomBase64Url(64);
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $redirectUri = $this->origin->redirectUri();

        DB::transaction(function () use ($actor, $cabinet, $redirectUri, $state, $verifier): void {
            GoogleDriveOAuthAttempt::query()
                ->where('cabinet_setting_id', $cabinet->getKey())
                ->where('actor_id', $actor->getKey())
                ->where('status', GoogleDriveOAuthAttempt::STATUS_PENDING)
                ->update([
                    'status' => GoogleDriveOAuthAttempt::STATUS_FAILED,
                    'encrypted_pkce_verifier' => null,
                    'failed_at' => now(),
                    'failure_code' => 'superseded',
                ]);

            GoogleDriveOAuthAttempt::query()->create([
                'state_sha256' => hash('sha256', $state),
                'encrypted_pkce_verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'cabinet_setting_id' => $cabinet->getKey(),
                'actor_id' => $actor->getKey(),
                'status' => GoogleDriveOAuthAttempt::STATUS_PENDING,
                'expires_at' => now()->addMinutes(self::ATTEMPT_TTL_MINUTES),
            ]);
        });

        return [
            'authorization_url' => $this->drive->authorizationUrl(
                $state,
                $challenge,
                $redirectUri,
            ),
        ];
    }

    public function complete(Request $request): void
    {
        $redirectUri = $this->origin->assertCallbackRequest($request);
        $state = $request->query('state');

        if (! is_string($state) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state) !== 1) {
            throw new GoogleDriveOAuthException('state_invalid');
        }

        $attempt = $this->claim($state, $redirectUri);

        try {
            $actor = $attempt->actor()->first();
            $cabinet = $attempt->cabinet()->first();

            if (! $actor instanceof User
                || ! $cabinet instanceof CabinetSetting
                || ! $this->installationMaintenance->allows($actor)
                || ! $actor->can(PermissionName::CONFIGURATION_MANAGE->value)
                || ! $actor->can(PermissionName::SETTINGS_MANAGE->value)
                || ($actor->cabinet_setting_id !== null
                    && (int) $actor->cabinet_setting_id !== (int) $cabinet->getKey())) {
                throw new GoogleDriveOAuthException('authorization_changed');
            }

            $providerError = $request->query('error');

            if (is_string($providerError) && $providerError !== '') {
                throw new GoogleDriveOAuthException('provider_denied');
            }

            $code = $request->query('code');

            if (! is_string($code)
                || $code === ''
                || strlen($code) > 4096
                || trim($code) !== $code
                || preg_match('/[\x00-\x1F\x7F]/', $code) === 1) {
                throw new GoogleDriveOAuthException('authorization_code_invalid');
            }

            $verifier = $attempt->encrypted_pkce_verifier;

            if (! is_string($verifier)
                || strlen($verifier) < 43
                || strlen($verifier) > 128
                || preg_match('/\A[A-Za-z0-9_-]+\z/', $verifier) !== 1) {
                throw new GoogleDriveOAuthException('pkce_verifier_unavailable');
            }

            $this->drive->connect($code, $verifier, $attempt->redirect_uri, $cabinet);

            $completed = GoogleDriveOAuthAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', GoogleDriveOAuthAttempt::STATUS_CLAIMED)
                ->update([
                    'status' => GoogleDriveOAuthAttempt::STATUS_COMPLETED,
                    'encrypted_pkce_verifier' => null,
                    'failure_code' => null,
                ]);

            if ($completed !== 1) {
                throw new GoogleDriveOAuthException('attempt_completion_conflict');
            }

            AuditLog::record('backup.drive_connected', $cabinet, [
                'provider' => 'google_drive',
                'oauth_profile' => 'installed_app_pkce_s256',
            ], (int) $actor->getKey());
            ApplicationEvent::record('CloudDriveConnected', context: [
                'provider' => 'google_drive',
                'oauth_profile' => 'installed_app_pkce_s256',
            ]);
        } catch (GoogleDriveOAuthException $exception) {
            $this->markFailed($attempt, $exception->reasonCode);
            $this->auditFailure($attempt, $exception->reasonCode);

            throw $exception;
        } catch (Throwable) {
            $this->markFailed($attempt, 'token_exchange_failed');
            $this->auditFailure($attempt, 'token_exchange_failed');

            throw new GoogleDriveOAuthException('token_exchange_failed');
        }
    }

    private function claim(string $state, string $redirectUri): GoogleDriveOAuthAttempt
    {
        $attempt = GoogleDriveOAuthAttempt::query()
            ->where('state_sha256', hash('sha256', $state))
            ->first();

        if (! $attempt instanceof GoogleDriveOAuthAttempt
            || ! hash_equals($attempt->redirect_uri, $redirectUri)) {
            throw new GoogleDriveOAuthException('attempt_not_found');
        }

        if ($attempt->expires_at->isPast()) {
            GoogleDriveOAuthAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', GoogleDriveOAuthAttempt::STATUS_PENDING)
                ->update([
                    'status' => GoogleDriveOAuthAttempt::STATUS_EXPIRED,
                    'encrypted_pkce_verifier' => null,
                    'failed_at' => now(),
                    'failure_code' => 'expired',
                ]);

            throw new GoogleDriveOAuthException('attempt_expired');
        }

        $claimed = GoogleDriveOAuthAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GoogleDriveOAuthAttempt::STATUS_PENDING)
            ->whereNull('consumed_at')
            ->whereNull('failed_at')
            ->where('expires_at', '>', now())
            ->where('redirect_uri', $redirectUri)
            ->update([
                'status' => GoogleDriveOAuthAttempt::STATUS_CLAIMED,
                'consumed_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new GoogleDriveOAuthException('attempt_already_consumed');
        }

        return $attempt->refresh();
    }

    private function markFailed(GoogleDriveOAuthAttempt $attempt, string $reasonCode): void
    {
        GoogleDriveOAuthAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GoogleDriveOAuthAttempt::STATUS_CLAIMED)
            ->update([
                'status' => GoogleDriveOAuthAttempt::STATUS_FAILED,
                'encrypted_pkce_verifier' => null,
                'failed_at' => now(),
                'failure_code' => $reasonCode,
            ]);
    }

    private function auditFailure(GoogleDriveOAuthAttempt $attempt, string $reasonCode): void
    {
        try {
            AuditLog::record(
                'backup.drive_oauth_failed',
                $attempt->cabinet()->first(),
                [
                    'provider' => 'google_drive',
                    'reason_code' => $reasonCode,
                ],
                $attempt->actor_id,
            );
            ApplicationEvent::record('CloudDriveConnectionFailed', 'warning', context: [
                'provider' => 'google_drive',
                'reason_code' => $reasonCode,
            ]);
        } catch (Throwable) {
            // Preserve the bounded OAuth failure if audit storage is unavailable.
        }
    }

    /** @param positive-int $bytes */
    private function randomBase64Url(int $bytes): string
    {
        return $this->base64Url(random_bytes($bytes));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
