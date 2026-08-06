<?php

namespace App\Services;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SessionLockService
{
    public const SESSION_USER_ID = 'medismart.session_lock.user_id';

    public const SESSION_LOCKED_AT = 'medismart.session_lock.locked_at';

    public const SESSION_LOCK_REASON = 'medismart.session_lock.reason';

    public const SESSION_LAST_ACTIVITY_AT = 'medismart.session_lock.last_activity_at';

    public const SESSION_INTENDED = 'medismart.session_lock.intended';

    public const SESSION_INSTANCE_ID = 'medismart.session_lock.instance_id';

    public function __construct(private readonly ApplicationSettingService $settings) {}

    public function synchronizeUser(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $session = $request->session();

        $previousUserId = $session->get(self::SESSION_USER_ID);

        if ((int) $previousUserId !== (int) $user->getKey()) {
            $session->forget([
                self::SESSION_LOCKED_AT,
                self::SESSION_LOCK_REASON,
                self::SESSION_LAST_ACTIVITY_AT,
                self::SESSION_INTENDED,
                self::SESSION_INSTANCE_ID,
            ]);

            if ($previousUserId !== null) {
                $session->forget(['auth.password_confirmed_at', '_old_input', 'errors']);
            }

            $session->put(self::SESSION_USER_ID, (int) $user->getKey());
            $session->put(self::SESSION_LAST_ACTIVITY_AT, now()->timestamp);
            $session->put(self::SESSION_INSTANCE_ID, Str::random(40));

            return;
        }

        if (! $this->validInstanceId($session->get(self::SESSION_INSTANCE_ID))) {
            $session->put(self::SESSION_INSTANCE_ID, Str::random(40));
        }
    }

    public function lockWhenIdle(Request $request): bool
    {
        $this->synchronizeUser($request);

        if ($this->isLocked($request)) {
            return false;
        }

        $lastActivityAt = $this->lastActivityTimestamp($request);

        if (now()->getTimestamp() - $lastActivityAt < $this->idleTimeoutSeconds()) {
            return false;
        }

        $intended = $request->isMethodSafe() ? $request->getRequestUri() : null;

        return $this->lock($request, 'idle', $intended);
    }

    public function lock(Request $request, string $reason, ?string $intended = null): bool
    {
        $this->synchronizeUser($request);
        $this->rememberIntended($request, $intended);

        if ($this->isLocked($request)) {
            return false;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $normalizedReason = $reason === 'idle' ? 'idle' : 'manual';
        $session = $request->session();

        $session->forget(['auth.password_confirmed_at', '_old_input', 'errors']);
        $session->put(self::SESSION_LOCKED_AT, now()->timestamp);
        $session->put(self::SESSION_LOCK_REASON, $normalizedReason);

        AuditLog::record('security.session_locked', $user, [
            'reason' => $normalizedReason,
        ]);

        return true;
    }

    public function unlock(Request $request, string $method): string
    {
        $user = $request->user();
        $session = $request->session();
        $intended = $this->intendedDestination($request);

        $session->forget([
            self::SESSION_LOCKED_AT,
            self::SESSION_LOCK_REASON,
            self::SESSION_INTENDED,
            'auth.password_confirmed_at',
            '_old_input',
            'errors',
        ]);
        $session->put(self::SESSION_LAST_ACTIVITY_AT, now()->timestamp);
        $session->put(self::SESSION_INSTANCE_ID, Str::random(40));
        $session->regenerate(true);
        $session->regenerateToken();

        if ($user instanceof User) {
            AuditLog::record('security.session_unlocked', $user, [
                'method' => $method,
            ]);
        }

        return $intended;
    }

    public function touch(Request $request): void
    {
        $this->synchronizeUser($request);

        if (! $this->isLocked($request)) {
            $request->session()->put(self::SESSION_LAST_ACTIVITY_AT, now()->timestamp);
        }
    }

    public function currentInstanceId(Request $request): string
    {
        $this->synchronizeUser($request);
        $value = $request->session()->get(self::SESSION_INSTANCE_ID);

        return is_string($value) && $this->validInstanceId($value)
            ? $value
            : '';
    }

    public function matchesCurrentInstance(Request $request, mixed $provided): bool
    {
        if (! is_string($provided) || ! $this->validInstanceId($provided)) {
            return false;
        }

        $current = $this->currentInstanceId($request);

        return $current !== '' && hash_equals($current, $provided);
    }

    public function isLocked(Request $request): bool
    {
        return $request->session()->has(self::SESSION_LOCKED_AT);
    }

    public function idleTimeoutSeconds(): int
    {
        return max(60, (int) $this->settings->get(
            ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES,
        ) * 60);
    }

    public function remainingSeconds(Request $request): int
    {
        if ($this->isLocked($request)) {
            return 0;
        }

        return max(
            0,
            $this->idleTimeoutSeconds() - (now()->getTimestamp() - $this->lastActivityTimestamp($request)),
        );
    }

    public function rememberIntended(Request $request, ?string $intended): void
    {
        $normalized = $this->normalizeIntended($intended);

        if ($normalized !== null && ! $request->session()->has(self::SESSION_INTENDED)) {
            $request->session()->put(self::SESSION_INTENDED, $normalized);
        }
    }

    private function lastActivityTimestamp(Request $request): int
    {
        $value = $request->session()->get(self::SESSION_LAST_ACTIVITY_AT);
        $timestamp = is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : 0;

        return $timestamp > 0 && $timestamp <= now()->getTimestamp()
            ? $timestamp
            : 0;
    }

    private function validInstanceId(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) === 40
            && ctype_alnum($value);
    }

    private function intendedDestination(Request $request): string
    {
        $stored = $request->session()->get(self::SESSION_INTENDED);

        return $this->normalizeIntended(is_string($stored) ? $stored : null)
            ?? route('dashboard', absolute: false);
    }

    private function normalizeIntended(?string $value): ?string
    {
        if ($value === null
            || $value === ''
            || strlen($value) > 2048
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_starts_with($value, '/\\')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_starts_with($value, '/session/')) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $value;
    }
}
