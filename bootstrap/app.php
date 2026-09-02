<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\DenyCabinetRegistrationOnHub;
use App\Http\Middleware\EnforceHubCabinetBinding;
use App\Http\Middleware\EnforceRemoteUploadBoundary;
use App\Http\Middleware\EnforceSessionLock;
use App\Http\Middleware\EnsureApiCabinetIsActive;
use App\Http\Middleware\EnsureCabinetIsActive;
use App\Http\Middleware\EnsureMobilePatient;
use App\Http\Middleware\EnsureMobilePlatformAdmin;
use App\Http\Middleware\EnsureMobileStaffCabinet;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MarkJsonOnlyEndpointsAsXhr;
use App\Http\Middleware\RecordInertiaPreviousUrl;
use App\Http\Middleware\SecureResponseHeaders;
use App\Http\Middleware\ThrottleCabinetRegistration;
use App\Support\PostLoginDestination;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

/**
 * Let the desktop launcher redirect Laravel's generated caches off the
 * installation directory.
 *
 * `Application::normalizeCachePath()` treats an `APP_*_CACHE` value as absolute
 * only when it starts with `/` or `\`. A Windows path such as
 * `C:\Users\...\AppData\...\cache\services.php` matches neither, so Laravel
 * appends it to the base path and tries to write inside `Program Files`, which
 * is read-only. The application then cannot register its service providers and
 * every request fails with `Class "view" does not exist`.
 *
 * Registering the drive-letter prefixes is a no-op everywhere else: no path on
 * Linux or macOS begins with `C:`.
 */
$registerWindowsCachePrefixes = static function (Application $app): Application {
    if (DIRECTORY_SEPARATOR !== '\\') {
        return $app;
    }

    foreach (range('A', 'Z') as $driveLetter) {
        $app->addAbsoluteCachePathPrefix($driveLetter.':');
    }

    return $app;
};

return $registerWindowsCachePrefixes(Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo(
            fn (Request $request): string => PostLoginDestination::for($request->user()),
        );
        $middleware->append(SecureResponseHeaders::class);
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Replace Laravel's proxy slot so normalization and the host/route
        // boundary run together before CORS, routing, sessions, or auth.
        $middleware->replace(TrustProxies::class, EnforceRemoteUploadBoundary::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'app/configuration/models/*/callback',
            'app/clinical-documents/*/callback',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'cabinet.active.api' => EnsureApiCabinetIsActive::class,
            'mobile.patient' => EnsureMobilePatient::class,
            'mobile.admin' => EnsureMobilePlatformAdmin::class,
            'mobile.staff.cabinet' => EnsureMobileStaffCabinet::class,
        ]);

        $middleware->api(prepend: [
            EnforceHubCabinetBinding::class,
        ]);

        $middleware->web(append: [
            // Runs inside StartSession, so it can mark a JSON-only fetch as XHR
            // before storeCurrentUrl() decides whether to record it as the
            // session's previous URL. See the middleware for why that matters.
            MarkJsonOnlyEndpointsAsXhr::class,
            // The mirror image of the above: Inertia visits are flagged XHR, so
            // storeCurrentUrl() never records them and back() stayed pinned to
            // the last full page load. See the middleware.
            RecordInertiaPreviousUrl::class,
            HandleAppearance::class,
            // The Hub boundary runs before the per-cabinet gates: on a Hub,
            // belonging to another cabinet is not a licence question, it is
            // the wrong machine.
            EnforceHubCabinetBinding::class,
            // Both self-scope to Fortify's registration routes, which cannot
            // be decorated reliably at boot time.
            ThrottleCabinetRegistration::class,
            DenyCabinetRegistrationOnHub::class,
            EnforceSessionLock::class,
            EnsureCabinetIsActive::class,
            HandleInertiaRequests::class,
            // Disabled: this emits one `Link: rel=preload` entry per Vite chunk,
            // which on the landing page reached ~14KB and pushed the response
            // headers past 16KB. Hostinger's `hcdn` edge rejects that — the
            // origin answered 200 while visitors got 307s and 504s. The HTML
            // already carries its own modulepreload tags, so nothing is lost
            // beyond the early hint. Re-enable only behind a header budget.
            // AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'pin',
            'pin_confirmation',
            'device_token',
            'passphrase',
            'passphrase_confirmation',
            'serial',
            'license_certificate',
            'license_code',
            'machine_fingerprint_hash',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create());
