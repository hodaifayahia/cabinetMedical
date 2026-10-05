<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\ForgotPasswordRequest;
use App\Http\Requests\Api\Mobile\ResetPasswordRequest;
use App\Mail\MobilePasswordResetCodeMail;
use App\Models\MobilePasswordReset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mobile "Forgot password". Step 1 e-mails a six-digit code to the address
 * on the account; step 2 trades that code for a new password and signs every
 * device out. There is no SMS gateway yet, so an account without an e-mail
 * cannot receive a code — the app says so, and the clinic can reset it.
 *
 * Step 1 always answers the same way, whether or not the identifier matches
 * an account, so it cannot be used to discover who is registered.
 */
class PasswordResetController extends Controller
{
    public const CODE_LIFETIME_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    /** A second request inside this window keeps the code already sent. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $user = $this->findUser($request->validated('identifier'));

        if ($user !== null && filled($user->email)) {
            $this->issueCode($user);
        }

        return response()->json([
            'message' => 'Si un compte correspond, un code de réinitialisation a été envoyé à son adresse e-mail.',
            'channel' => 'email',
            'expires_in_minutes' => self::CODE_LIFETIME_MINUTES,
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->findUser($data['identifier']);

        /** @var MobilePasswordReset|null $reset */
        $reset = $user === null
            ? null
            : MobilePasswordReset::query()->where('user_id', $user->getKey())->first();

        if ($user === null || $reset === null || $reset->expires_at->isPast() || $reset->attempts >= self::MAX_ATTEMPTS) {
            $reset?->delete();

            return $this->codeRejected('reset_code_expired', 'Ce code a expiré. Demandez-en un nouveau.');
        }

        if (! Hash::check($data['code'], $reset->code_hash)) {
            $reset->increment('attempts');

            if ($reset->attempts >= self::MAX_ATTEMPTS) {
                $reset->delete();

                return $this->codeRejected('reset_code_expired', 'Trop d\'essais. Demandez un nouveau code.');
            }

            return $this->codeRejected('reset_code_invalid', 'Ce code est incorrect.');
        }

        DB::transaction(function () use ($user, $reset, $data): void {
            $user->forceFill(['password' => $data['password']])->save();
            $reset->delete();
            // Whoever knew the old password is signed out everywhere.
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.',
        ]);
    }

    /**
     * Same matching as sign-in: an e-mail is matched as is; a phone is
     * matched as typed and in its canonical 0XXXXXXXXX form, because accounts
     * created on the web may keep spaces or a +213 prefix.
     */
    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return User::query()->where('email', $identifier)->first();
        }

        $candidates = array_values(array_unique(array_filter([
            $identifier,
            $this->canonicalPhone($identifier),
        ])));

        return User::query()->whereIn('phone', $candidates)->orderBy('id')->first();
    }

    private function canonicalPhone(string $value): ?string
    {
        // Arabic-Indic and Eastern Arabic-Indic digits from an Arabic keyboard.
        $digits = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        if (str_starts_with($digits, '00213')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '213') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return preg_match('/^0[5-7]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    private function issueCode(User $user): void
    {
        $existing = MobilePasswordReset::query()->where('user_id', $user->getKey())->first();

        if ($existing !== null
            && $existing->created_at !== null
            && $existing->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            return;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        // Replace, never update: `created_at` drives the resend cooldown.
        $existing?->delete();
        MobilePasswordReset::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
        ]);

        try {
            Mail::to($user->email)->send(new MobilePasswordResetCodeMail(
                code: $code,
                name: $user->name,
                expiresInMinutes: self::CODE_LIFETIME_MINUTES,
            ));
        } catch (Throwable $exception) {
            // The answer must stay the same either way; the log is the signal.
            Log::warning('Mobile password reset e-mail could not be sent.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function codeRejected(string $reason, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
            'errors' => ['code' => [$message]],
        ], 422);
    }
}
