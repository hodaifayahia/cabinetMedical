<?php

namespace App\Services;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class QrUploadService
{
    public function __construct(
        private readonly NetworkService $network,
        private readonly ApplicationSettingService $settings,
    ) {}

    public function defaultMode(): string
    {
        return $this->settings->get(ApplicationSettingRegistry::UPLOAD_DEFAULT_MODE);
    }

    /**
     * @param  array{
     *     expires_after_minutes?: int,
     *     maximum_files?: int,
     *     maximum_individual_bytes?: int,
     *     maximum_total_bytes?: int,
     *     allowed_mime_types?: list<string>
     * }  $options
     * @return array{session: UploadSession, token: string, url: string|null}
     */
    public function create(
        string $mode,
        User $creator,
        ?Patient $patient = null,
        string $purpose = 'medical_document',
        array $options = [],
    ): array {
        if (! in_array($mode, ['local', 'remote', 'relay'], true)) {
            throw new InvalidArgumentException('Unsupported upload mode.');
        }

        [$selector, $verifier, $token] = $this->newToken();
        $expiresAfter = max(1, min(30, (int) ($options['expires_after_minutes']
            ?? $this->settings->get(ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES))));
        $configuredMaximumFiles = max(1, min(50, (int) config('medismart.uploads.maximum_files', 10)));
        $configuredMaximumIndividualBytes = max(1, (int) config(
            'medismart.uploads.maximum_individual_bytes',
            20971520,
        ));
        $configuredMaximumTotalBytes = max(1, (int) config(
            'medismart.uploads.maximum_total_bytes',
            104857600,
        ));
        $defaultMaximumFiles = (int) $this->settings->get(ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES);
        $defaultMaximumIndividualBytes = (int) $this->settings->get(
            ApplicationSettingRegistry::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES,
        );
        $defaultMaximumTotalBytes = (int) $this->settings->get(
            ApplicationSettingRegistry::UPLOAD_MAXIMUM_TOTAL_BYTES,
        );
        $maximumFiles = max(1, min(
            $configuredMaximumFiles,
            (int) ($options['maximum_files'] ?? $defaultMaximumFiles),
        ));
        $maximumTotalBytes = max(1, min(
            $configuredMaximumTotalBytes,
            (int) ($options['maximum_total_bytes'] ?? $defaultMaximumTotalBytes),
        ));
        $maximumIndividualBytes = max(1, min(
            $configuredMaximumIndividualBytes,
            $maximumTotalBytes,
            (int) ($options['maximum_individual_bytes'] ?? $defaultMaximumIndividualBytes),
        ));
        $configuredMimeTypes = array_values(array_unique(array_filter(
            config('medismart.uploads.allowed_mime_types', []),
            static fn (mixed $mime): bool => is_string($mime) && str_contains($mime, '/'),
        )));
        $requestedMimeTypes = array_values(array_unique(array_filter(
            $options['allowed_mime_types'] ?? $configuredMimeTypes,
            static fn (mixed $mime): bool => is_string($mime) && str_contains($mime, '/'),
        )));
        $allowedMimeTypes = array_values(array_intersect($configuredMimeTypes, $requestedMimeTypes));

        if ($allowedMimeTypes === []) {
            throw new InvalidArgumentException('At least one upload MIME type must be allowed.');
        }

        $session = DB::transaction(function () use (
            $mode,
            $creator,
            $patient,
            $purpose,
            $selector,
            $token,
            $expiresAfter,
            $maximumFiles,
            $maximumIndividualBytes,
            $maximumTotalBytes,
            $allowedMimeTypes,
        ): UploadSession {
            $session = UploadSession::query()->create([
                'public_selector' => $selector,
                'public_token_hash' => $this->hashToken($token),
                'mode' => $mode,
                'purpose' => $purpose,
                'patient_id' => $patient?->getKey(),
                'created_by' => $creator->getKey(),
                'expires_at' => now()->addMinutes($expiresAfter),
                'maximum_files' => $maximumFiles,
                'maximum_individual_bytes' => $maximumIndividualBytes,
                'maximum_total_bytes' => $maximumTotalBytes,
                'allowed_mime_types' => $allowedMimeTypes,
                'status' => UploadSession::STATUS_PENDING,
            ]);

            AuditLog::record('upload_session.created', $session, [
                'mode' => $mode,
                'purpose' => $purpose,
                'expires_at' => $session->expires_at->toIso8601String(),
            ], $creator->getKey());

            return $session;
        });

        $this->recordApplicationEvent('UploadSessionCreated', [
            'upload_session_id' => (string) $session->getKey(),
            'mode' => $session->mode,
            'purpose' => $session->purpose,
        ]);

        return [
            'session' => $session,
            'token' => $token,
            'url' => $this->urlForToken($token, $mode),
        ];
    }

    public function resolve(string $tokenOrSelector, ?string $verifier = null): ?UploadSession
    {
        $session = $this->findByToken($tokenOrSelector, $verifier);

        if ($session === null) {
            return null;
        }

        if ($session->expires_at->isPast() && $session->isUsable() === false
            && in_array($session->status, [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING], true)) {
            $session->update(['status' => UploadSession::STATUS_EXPIRED]);
            AuditLog::record('upload_session.expired', $session);
            $this->recordApplicationEvent('UploadSessionExpired', [
                'upload_session_id' => (string) $session->getKey(),
            ]);
        }

        return $session->isUsable() ? $session : null;
    }

    public function findByToken(string $tokenOrSelector, ?string $verifier = null): ?UploadSession
    {
        $parts = $this->tokenParts($tokenOrSelector, $verifier);

        if ($parts === null) {
            return null;
        }

        [$selector, , $token] = $parts;

        return UploadSession::query()
            ->where('public_selector', $selector)
            ->where('public_token_hash', $this->hashToken($token))
            ->first();
    }

    public function findBySelector(string $selector): ?UploadSession
    {
        if (preg_match('/\A[A-Za-z0-9_-]{22}\z/', $selector) !== 1) {
            return null;
        }

        return UploadSession::query()->where('public_selector', $selector)->first();
    }

    public function revoke(UploadSession $session, ?User $actor = null): void
    {
        $updated = UploadSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING])
            ->update([
                'status' => UploadSession::STATUS_REVOKED,
                'public_token_hash' => hash('sha256', random_bytes(32)),
            ]);

        if ($updated !== 1) {
            return;
        }

        $session->refresh();
        AuditLog::record('upload_session.revoked', $session, [], $actor?->getKey());
        $this->recordApplicationEvent('UploadSessionRevoked', [
            'upload_session_id' => (string) $session->getKey(),
        ]);
    }

    public function complete(UploadSession $session, ?User $actor = null): void
    {
        $completedAt = now();
        $updated = UploadSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING])
            ->where('expires_at', '>', $completedAt)
            ->update([
                'status' => UploadSession::STATUS_COMPLETED,
                'completed_at' => $completedAt,
                'public_token_hash' => hash('sha256', random_bytes(32)),
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('The upload session is no longer usable.');
        }

        $session->refresh();
        AuditLog::record('upload_session.completed', $session, [], $actor?->getKey());
        $this->recordApplicationEvent('UploadSessionCompleted', [
            'upload_session_id' => (string) $session->getKey(),
        ]);
    }

    public function urlForToken(string $token, string $mode): ?string
    {
        $parts = $this->tokenParts($token);

        if ($parts === null) {
            return null;
        }

        [$selector, $verifier] = $parts;
        $baseUrl = match ($mode) {
            'local' => $this->network->localUploadBaseUrl(),
            'remote', 'relay' => config('medismart.runtime.remote_upload_url'),
            default => null,
        };

        return is_string($baseUrl) && $baseUrl !== ''
            ? rtrim($baseUrl, '/').'/upload/'.rawurlencode($selector).'#v='.rawurlencode($verifier)
            : null;
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{string, string, string} */
    private function newToken(): array
    {
        do {
            $selector = $this->base64Url(random_bytes(16));
        } while (UploadSession::query()->where('public_selector', $selector)->exists());

        $verifier = $this->base64Url(random_bytes(32));

        return [$selector, $verifier, $selector.'.'.$verifier];
    }

    /** @return array{string, string, string}|null */
    private function tokenParts(string $tokenOrSelector, ?string $verifier = null): ?array
    {
        if ($verifier === null) {
            $parts = explode('.', $tokenOrSelector, 2);

            if (count($parts) !== 2) {
                return null;
            }

            [$selector, $verifier] = $parts;
        } else {
            $selector = $tokenOrSelector;
        }

        if (preg_match('/\A[A-Za-z0-9_-]{22}\z/', $selector) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $verifier) !== 1) {
            return null;
        }

        return [$selector, $verifier, $selector.'.'.$verifier];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param array<string, bool|int|string|null> $context */
    private function recordApplicationEvent(string $event, array $context): void
    {
        try {
            ApplicationEvent::record($event, context: $context);
        } catch (Throwable) {
            // Operational polling history must never change the result of an
            // already committed upload-session transition.
        }
    }
}
