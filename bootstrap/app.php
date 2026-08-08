<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnforceRemoteUploadBoundary;
use App\Http\Middleware\EnforceSessionLock;
use App\Http\Middleware\EnsureApiCabinetIsActive;
use App\Http\Middleware\EnsureCabinetIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecureResponseHeaders;
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

return Application::configure(basePath: dirname(__DIR__))
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
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            EnforceSessionLock::class,
            EnsureCabinetIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
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
    })->create();
