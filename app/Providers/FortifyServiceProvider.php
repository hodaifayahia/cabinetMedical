<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\Wilayas;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // Fortify owns the registration routes; attach a throttle to the store
        // endpoint once every route has been registered.
        $this->booted(function (): void {
            $route = app('router')->getRoutes()->getByName('register.store');
            $route?->middleware('throttle:registration');
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => $this->passwordResetDeliveryAvailable(),
            'canRegister' => true,
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function () {
            return Inertia::render('auth/Register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'specialtySuggestions' => app(MedicalSpecialtyCatalog::class)->labels(),
                'wilayas' => Wilayas::options(),
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('desktop-cabinet-login', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email'))));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by(hash('sha256', "desktop-login|{$email}|{$ip}")),
                Limit::perMinute(20)->by(hash('sha256', "desktop-login-ip|{$ip}")),
            ];
        });

        RateLimiter::for('desktop-pin-login', function (Request $request) {
            $deviceToken = (string) $request->input('device_token');
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(10)->by(hash('sha256', "desktop-pin|{$deviceToken}|{$ip}")),
                Limit::perMinute(30)->by(hash('sha256', "desktop-pin-ip|{$ip}")),
            ];
        });

        RateLimiter::for('desktop-pin-enroll', function (Request $request) {
            $userId = (string) $request->user()?->getAuthIdentifier();
            $ip = (string) $request->ip();

            return [
                Limit::perMinutes(10, 10)->by(hash('sha256', "desktop-pin-enroll|{$userId}|{$ip}")),
                Limit::perMinutes(10, 30)->by(hash('sha256', "desktop-pin-enroll-ip|{$ip}")),
            ];
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    private function passwordResetDeliveryAvailable(): bool
    {
        if (! Features::enabled(Features::resetPasswords())) {
            return false;
        }

        $mailer = (string) config('mail.default');

        return ! in_array($mailer, ['', 'array', 'log'], true)
            && is_string(config("mail.mailers.{$mailer}.host"))
            && trim((string) config("mail.mailers.{$mailer}.host")) !== '';
    }
}
