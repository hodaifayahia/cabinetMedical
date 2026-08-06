<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SessionLockService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionLock
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'session-lock.show',
        'session-lock.lock',
        'session-lock.lock-idle',
        'session-lock.unlock',
        'logout',
        'filament.admin.auth.logout',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'verification.notice',
        'verification.send',
        'verification.verify',
        'health',
        'app.configuration.backup.google.callback',
    ];

    public function __construct(private readonly SessionLockService $sessionLock) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return $next($request);
        }

        $this->sessionLock->synchronizeUser($request);
        $this->sessionLock->lockWhenIdle($request);

        if (! $this->sessionLock->isLocked($request) || $this->routeIsAllowed($request)) {
            $response = $next($request);

            if ($request->routeIs('session-lock.show')) {
                $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
            }

            return $response;
        }

        if ($request->isMethodSafe()) {
            $this->sessionLock->rememberIntended($request, $request->getRequestUri());
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('session-lock.show'));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'La session est verrouillée.',
            ], 423, [
                'Cache-Control' => 'no-store, private, max-age=0',
            ]);
        }

        return to_route('session-lock.show');
    }

    private function routeIsAllowed(Request $request): bool
    {
        foreach (self::ALLOWED_ROUTE_NAMES as $routeName) {
            if ($request->routeIs($routeName)) {
                return true;
            }
        }

        return $request->routeIs('upload.*');
    }
}
