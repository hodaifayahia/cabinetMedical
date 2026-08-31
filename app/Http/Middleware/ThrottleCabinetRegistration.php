<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the `registration` rate limiter to cabinet registration.
 *
 * The limiter has been defined in AppServiceProvider all along, but the code
 * that attached it to Fortify's route ran before Laravel had loaded that
 * route, so it silently never applied and registration was unthrottled. Route
 * loading is itself deferred to an application booted callback, so no
 * ordering this provider can arrange is dependable — the middleware group is.
 *
 * The real ThrottleRequests middleware does the work, so the limiter name,
 * the 429 response and the RateLimit headers all behave exactly as they would
 * had the route carried `throttle:registration` directly.
 */
class ThrottleCabinetRegistration
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('register.store')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, 'registration');
    }
}
