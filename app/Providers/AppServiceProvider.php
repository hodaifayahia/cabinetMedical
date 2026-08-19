<?php

namespace App\Providers;

use App\Backups\AutomaticBackupCreator;
use App\Backups\BackupArchiveVerifier;
use App\Backups\LocalAutomaticBackupCreator;
use App\Backups\MsBackupArchiveVerifier;
use App\Licensing\HttpLicenseActivationProvider;
use App\Licensing\LicenseActivationProvider;
use App\Models\AuditLog;
use App\Models\DesktopDownloadLead;
use App\Models\User;
use App\Services\SessionLockService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BackupArchiveVerifier::class, MsBackupArchiveVerifier::class);
        $this->app->bind(AutomaticBackupCreator::class, LocalAutomaticBackupCreator::class);
        $this->app->bind(LicenseActivationProvider::class, HttpLicenseActivationProvider::class);

        // Telescope is a dev-only dependency (see composer.json "dont-discover").
        // Register it manually so production never references a missing class.
        if ($this->app->environment('local')
            && (bool) config('telescope.enabled', false)
            && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthAuditing();

        // Platform staff operate the central back office across every cabinet
        // and are granted all abilities so the existing Filament resources
        // (Patients, Users, ...) remain usable cross-tenant.
        Gate::before(static function (?User $user): ?bool {
            return $user?->is_platform_admin === true ? true : null;
        });
    }

    /**
     * Record sign-in and sign-out events for the admin activity journal.
     */
    protected function configureAuthAuditing(): void
    {
        Event::listen(Login::class, static function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            AuditLog::record('auth.login', $event->user, ['guard' => $event->guard], (int) $event->user->getAuthIdentifier());
        });

        Event::listen(Logout::class, static function (Logout $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            AuditLog::record('auth.logout', $event->user, ['guard' => $event->guard], (int) $event->user->getAuthIdentifier());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        RateLimiter::for('public-uploads', static function (Request $request): Limit {
            $routeName = (string) $request->route()?->getName();
            $maximum = match ($routeName) {
                'upload.files.store' => 12,
                'upload.complete' => 6,
                'upload.session' => 20,
                default => 60,
            };
            $key = hash('sha256', implode('|', [
                (string) $request->ip(),
                (string) $request->route('selector'),
                $routeName,
            ]));

            return Limit::perMinute($maximum)->by($key);
        });

        RateLimiter::for('registration', static function (Request $request): Limit {
            $key = hash('sha256', implode('|', [
                (string) $request->input('email'),
                (string) $request->ip(),
            ]));

            return Limit::perMinutes(10, 5)->by($key);
        });

        RateLimiter::for('cabinet-join', static function (Request $request): Limit {
            $key = hash('sha256', implode('|', [
                (string) $request->input('email'),
                (string) $request->ip(),
            ]));

            return Limit::perMinutes(10, 8)->by($key);
        });

        RateLimiter::for('desktop-download-leads', static function (Request $request): array {
            $emailAndIp = hash('sha256', implode('|', [
                Str::lower(trim((string) $request->input('email'))),
                (string) $request->ip(),
            ]));
            $ipOnly = hash('sha256', (string) $request->ip());

            return [
                Limit::perHour(5)->by($emailAndIp),
                Limit::perHour(20)->by($ipOnly),
            ];
        });

        RateLimiter::for('desktop-download-files', static function (Request $request): Limit {
            $lead = $request->route('lead');
            $leadKey = $lead instanceof DesktopDownloadLead
                ? $lead->getRouteKey()
                : (string) $lead;
            $key = hash('sha256', implode('|', [
                $leadKey,
                (string) $request->ip(),
            ]));

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('license-activation', static function (Request $request): Limit {
            $key = hash('sha256', implode('|', [
                (string) $request->user()?->getAuthIdentifier(),
                (string) $request->ip(),
            ]));

            return Limit::perMinute(10)
                ->by($key)
                ->response(static function (Request $request, array $headers) {
                    $message = 'Trop de tentatives d’activation. Patientez une minute puis réessayez.';

                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => $message,
                            'errors' => ['license_code' => [$message]],
                        ], 429, $headers);
                    }

                    $errorKey = $request->routeIs('cabinet.license.redeem')
                        ? 'license_code'
                        : 'license_activation';
                    $response = back()->withErrors([$errorKey => $message]);
                    $response->headers->add($headers);

                    return $response;
                });
        });

        RateLimiter::for('offline-restore-prepare', static function (Request $request): Limit {
            $key = hash('sha256', implode('|', [
                (string) $request->user()?->getAuthIdentifier(),
                (string) $request->ip(),
            ]));

            return Limit::perMinutes(10, 5)->by($key);
        });

        RateLimiter::for('update-install-prepare', static function (Request $request): Limit {
            $key = hash('sha256', implode('|', [
                (string) $request->user()?->getAuthIdentifier(),
                (string) $request->ip(),
            ]));

            return Limit::perMinutes(10, 3)->by($key);
        });

        RateLimiter::for('session-unlock', static function (Request $request): Limit {
            $sessionInstance = $request->hasSession()
                ? $request->session()->get(SessionLockService::SESSION_INSTANCE_ID)
                : null;

            if ($request->hasSession() && ! is_string($sessionInstance)) {
                $sessionInstance = Str::random(40);
                $request->session()->put(
                    SessionLockService::SESSION_INSTANCE_ID,
                    $sessionInstance,
                );
            }

            $key = hash('sha256', implode('|', [
                (string) $request->user()?->getAuthIdentifier(),
                is_string($sessionInstance) ? $sessionInstance : 'no-session',
                (string) $request->ip(),
            ]));

            return Limit::perMinutes(10, 5)
                ->by($key)
                ->response(static function (Request $request, array $headers) {
                    $message = 'Trop de tentatives. Patientez avant de réessayer.';

                    if ($request->expectsJson()) {
                        return response()->json(['message' => $message], 429, $headers);
                    }

                    $response = to_route('session-lock.show')
                        ->withErrors(['credential' => $message]);
                    $response->headers->add($headers);

                    return $response;
                });
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
