<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\DesktopPinCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Lets someone who forgot both their password and their desktop PIN get back
 * in without e-mail, SMS or Internet.
 *
 * - Recovery codes: ten one-time codes each user prints or saves once.
 * - Poste principal key: a one-time code written to a file inside this
 *   installation's data folder. Reading it needs a Windows session on the
 *   computer that holds the database, i.e. the same access as the data itself.
 * - A cabinet manager can also reset a colleague's password and PIN from
 *   Personnel (see StaffIndexController).
 */
final class AccountRecoveryService
{
    public const CODE_COUNT = 10;

    public const INVALID_MESSAGE = 'Adresse e-mail ou code de secours invalide.';

    public const DEVICE_CODE_TTL_MINUTES = 15;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const DEVICE_CACHE_KEY = 'account-recovery:device-code';

    /**
     * Creates a new set of codes, invalidating the previous ones.
     *
     * @return list<string> the codes in clear, shown to the user once
     */
    public function generateCodes(User $user): array
    {
        $codes = [];

        while (count($codes) < self::CODE_COUNT) {
            $code = $this->randomCode(12);
            $codes[$code] = true;
        }

        $codes = array_keys($codes);

        $user->forceFill([
            'account_recovery_codes' => json_encode(array_map($this->hash(...), $codes)),
            'account_recovery_codes_generated_at' => now(),
        ])->save();

        AuditLog::record('security.recovery_codes_generated', $user, [
            'count' => count($codes),
        ], $user->getKey());

        return array_map($this->format(...), $codes);
    }

    public function remainingCodes(User $user): int
    {
        return count($this->storedHashes($user));
    }

    /**
     * Resets the password of the account with a one-time recovery code.
     */
    public function resetWithCode(string $email, string $code, string $password): User
    {
        $normalized = $this->normalize($code);

        $user = DB::transaction(function () use ($email, $normalized, $password): ?User {
            $user = $this->findByEmail($email, lock: true);
            $hashes = $user instanceof User ? $this->storedHashes($user) : [];
            $candidate = $normalized === '' ? '' : $this->hash($normalized);
            $match = null;

            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $candidate)) {
                    $match = $index;
                }
            }

            if (! $user instanceof User || $match === null) {
                AuditLog::record('security.recovery_code_failed', $user, [
                    'state' => 'invalid',
                ], $user?->getKey());

                return null;
            }

            unset($hashes[$match]);
            $user->forceFill([
                'account_recovery_codes' => json_encode(array_values($hashes)),
            ]);
            $this->applyNewPassword($user, $password, 'recovery_code');

            return $user;
        });

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['code' => self::INVALID_MESSAGE]);
        }

        return $user;
    }

    /**
     * Whether this request comes from the computer that runs the local
     * database (the desktop window itself), not from another poste.
     */
    public function deviceRecoveryAvailable(Request $request): bool
    {
        if (! (bool) config('medismart.runtime.desktop_supervised', false)) {
            return false;
        }

        if (! in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            return false;
        }

        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP', 'CF-Connecting-IP'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return in_array(strtolower($request->getHost()), ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
    }

    /**
     * Writes a fresh one-time code to the recovery file and returns its path.
     */
    public function issueDeviceCode(): string
    {
        $code = $this->randomCode(8);
        $path = $this->deviceCodePath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode(PHP_EOL, [
            'Drclick — clé de récupération du poste principal',
            '',
            'Code : '.$this->format($code),
            'Valable '.self::DEVICE_CODE_TTL_MINUTES.' minutes, une seule fois.',
            'Créé le '.now()->format('d/m/Y à H:i'),
            '',
            'Saisissez ce code dans Drclick (Mot de passe oublié › Clé du poste principal)',
            'puis supprimez ce fichier.',
            '',
        ]));

        Cache::put(self::DEVICE_CACHE_KEY, [
            'hash' => $this->hash($code),
            'attempts' => 0,
        ], now()->addMinutes(self::DEVICE_CODE_TTL_MINUTES));

        AuditLog::record('security.device_recovery_code_issued', null, ['state' => 'issued']);

        return $path;
    }

    public function deviceCodePath(): string
    {
        return storage_path('app/private/recovery/CODE-DE-RECUPERATION.txt');
    }

    public function resetWithDeviceCode(string $email, string $code, string $password): User
    {
        $stored = Cache::get(self::DEVICE_CACHE_KEY);
        $valid = is_array($stored)
            && is_string($stored['hash'] ?? null)
            && hash_equals($stored['hash'], $this->hash($this->normalize($code)));

        if (! $valid) {
            if (is_array($stored)) {
                $attempts = (int) ($stored['attempts'] ?? 0) + 1;

                // Five wrong guesses burn the code: a new file must be made.
                $attempts >= 5
                    ? $this->forgetDeviceCode()
                    : Cache::put(self::DEVICE_CACHE_KEY, [...$stored, 'attempts' => $attempts], now()->addMinutes(self::DEVICE_CODE_TTL_MINUTES));
            }

            throw ValidationException::withMessages([
                'code' => 'Code du poste invalide ou expiré. Générez un nouveau fichier.',
            ]);
        }

        $user = DB::transaction(function () use ($email, $password): ?User {
            $user = $this->findByEmail($email, lock: true);

            if (! $user instanceof User) {
                return null;
            }

            $this->applyNewPassword($user, $password, 'device_key');

            return $user;
        });

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'email' => 'Aucun compte de ce poste ne correspond à cette adresse e-mail. '
                    .'Si ce compte existe en ligne, revenez à la connexion et choisissez « Cabinet existant ».',
            ]);
        }

        $this->forgetDeviceCode();

        return $user;
    }

    /**
     * Revokes every desktop PIN and lock-screen PIN of a colleague so they can
     * set new ones at their next sign-in.
     */
    public function resetPins(User $target, User $actor): int
    {
        return DB::transaction(function () use ($target, $actor): int {
            $revoked = DesktopPinCredential::withoutCabinetScope()
                ->where('user_id', $target->getKey())
                ->delete();
            $target->forceFill(['local_pin_hash' => null])->save();

            AuditLog::record('security.pins_reset_by_manager', $target, [
                'credentials_revoked' => $revoked,
            ], $actor->getKey());

            return $revoked;
        });
    }

    private function applyNewPassword(User $user, string $password, string $method): void
    {
        // Saving a new password already revokes every desktop PIN (User::booted);
        // the lock-screen PIN is forgotten too so it can be set again.
        $user->forceFill([
            'password' => Hash::make($password),
            'local_pin_hash' => null,
            'remember_token' => null,
        ])->save();

        if (config('session.driver') === 'database') {
            // Signs the account out everywhere it was still open.
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        AuditLog::record('security.password_recovered', $user, [
            'method' => $method,
        ], $user->getKey());
    }

    private function forgetDeviceCode(): void
    {
        Cache::forget(self::DEVICE_CACHE_KEY);

        try {
            File::delete($this->deviceCodePath());
        } catch (\Throwable) {
            // The cache entry is the authority; a leftover file is harmless.
        }
    }

    private function findByEmail(string $email, bool $lock = false): ?User
    {
        $query = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return list<string>
     */
    private function storedHashes(User $user): array
    {
        $decoded = json_decode((string) $user->getAttribute('account_recovery_codes'), true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, is_string(...)))
            : [];
    }

    private function randomCode(int $length): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private function normalize(string $code): string
    {
        // Dashes and spaces are presentation only; letters are case-free.
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', 'account-recovery|'.$code, (string) config('app.key'));
    }
}
