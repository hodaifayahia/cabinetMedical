<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\DesktopPinCredential;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class DesktopPinService
{
    public const INVALID_CREDENTIAL_MESSAGE = 'Les informations de connexion sont invalides.';

    public const ENROLLMENT_CONFLICT_MESSAGE = 'Cet appareil ne peut pas être configuré avec ce compte.';

    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    private static ?string $dummyPinHash = null;

    public function __construct(
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * Bind or rotate a four-digit PIN for one authenticated user and one opaque
     * desktop installation token.
     */
    public function enroll(
        User $user,
        string $deviceToken,
        string $pin,
        string $deviceName,
    ): DesktopPinCredential {
        $this->assertMayEnroll($user);
        $tokenHash = $this->hashDeviceToken($deviceToken);

        try {
            return DB::transaction(function () use ($user, $tokenHash, $pin, $deviceName): DesktopPinCredential {
                $credential = DesktopPinCredential::withoutCabinetScope()
                    ->where('device_token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if ($credential !== null && $credential->user_id !== $user->getKey()) {
                    throw ValidationException::withMessages([
                        'device_token' => self::ENROLLMENT_CONFLICT_MESSAGE,
                    ]);
                }

                $created = $credential === null;
                $credential ??= new DesktopPinCredential;
                $credential->forceFill([
                    'user_id' => $user->getKey(),
                    'cabinet_id' => $user->cabinet_id,
                    'device_token_hash' => $tokenHash,
                    'device_name' => $deviceName,
                    'pin_hash' => Hash::make($pin),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                AuditLog::record(
                    $created ? 'security.desktop_pin_enrolled' : 'security.desktop_pin_changed',
                    $credential,
                    ['state' => $created ? 'configured' : 'changed'],
                    $user->getKey(),
                );

                return $credential;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent enrollment may win after our initial absent-row read.
            // Never reveal which account already owns the device token.
            throw ValidationException::withMessages([
                'device_token' => self::ENROLLMENT_CONFLICT_MESSAGE,
            ]);
        }
    }

    public function canEnroll(User $user): bool
    {
        $cabinet = $user->cabinet;

        if ($user->is_platform_admin || $cabinet === null || $user->cabinet_id === null) {
            return false;
        }

        $isPendingOwner = $cabinet->isPending()
            && $cabinet->owner_user_id === $user->getKey();

        return $isPendingOwner || $this->access->denialReason($user) === null;
    }

    /**
     * Resolve a guest desktop PIN attempt. All failure modes intentionally share
     * one message; the opaque device token is the high-entropy device factor.
     */
    public function authenticate(string $deviceToken, string $pin): User
    {
        $tokenHash = $this->hashDeviceToken($deviceToken);

        $user = DB::transaction(function () use ($tokenHash, $pin): ?User {
            $credential = DesktopPinCredential::withoutCabinetScope()
                ->where('device_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($credential === null) {
                $this->checkDummyPin($pin);
                AuditLog::record('auth.desktop_pin_login_failed', null, [
                    'state' => 'invalid',
                ]);

                return null;
            }

            $user = $credential->user()->first();
            $now = now();

            if ($credential->locked_until?->greaterThan($now) === true) {
                // Retain a password-hash check for less distinguishable timing,
                // but a correct PIN never bypasses an active persistent lockout.
                $this->pinMatches($pin, $credential->pin_hash);
                $this->recordFailedLogin($credential, $user, 'locked');

                return null;
            }

            if ($credential->locked_until !== null) {
                $credential->forceFill([
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]);
            }

            if (! $this->pinMatches($pin, $credential->pin_hash)) {
                $failedAttempts = $credential->failed_attempts + 1;
                $credential->forceFill([
                    'failed_attempts' => $failedAttempts,
                    'locked_until' => $failedAttempts >= self::MAX_FAILED_ATTEMPTS
                        ? $now->copy()->addMinutes(self::LOCKOUT_MINUTES)
                        : null,
                ])->save();
                $this->recordFailedLogin(
                    $credential,
                    $user,
                    $failedAttempts >= self::MAX_FAILED_ATTEMPTS ? 'locked' : 'invalid',
                );

                return null;
            }

            if (! $user instanceof User
                || $user->is_platform_admin
                || $user->cabinet_id === null
                || (int) $credential->cabinet_id !== (int) $user->cabinet_id) {
                $this->recordFailedLogin($credential, $user, 'invalid');

                return null;
            }

            $updates = [
                'failed_attempts' => 0,
                'locked_until' => null,
                'last_used_at' => $now,
            ];

            if (Hash::needsRehash($credential->pin_hash)) {
                $updates['pin_hash'] = Hash::make($pin);
            }

            $credential->forceFill($updates)->save();

            AuditLog::record('auth.desktop_pin_login_succeeded', $credential, [
                'state' => 'authenticated',
            ], $user->getKey());

            return $user;
        });

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'pin' => self::INVALID_CREDENTIAL_MESSAGE,
            ]);
        }

        return $user;
    }

    public function hashDeviceToken(string $deviceToken): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new LogicException('APP_KEY is required for desktop PIN credentials.');
        }

        return hash_hmac('sha256', $deviceToken, $key);
    }

    private function assertMayEnroll(User $user): void
    {
        if ($user->is_platform_admin || $user->cabinet === null || $user->cabinet_id === null) {
            throw new AuthorizationException('A cabinet account is required.');
        }

        if (! $this->canEnroll($user)) {
            throw new AuthorizationException('This cabinet account cannot enroll a desktop PIN.');
        }
    }

    private function recordFailedLogin(
        DesktopPinCredential $credential,
        ?User $user,
        string $state,
    ): void {
        AuditLog::record('auth.desktop_pin_login_failed', $credential, [
            'state' => $state,
        ], $user?->getKey());
    }

    private function pinMatches(string $pin, string $hash): bool
    {
        try {
            return Hash::check($pin, $hash);
        } catch (Throwable) {
            return false;
        }
    }

    private function checkDummyPin(string $pin): void
    {
        self::$dummyPinHash ??= Hash::make('0000');
        $this->pinMatches($pin, self::$dummyPinHash);
    }
}
