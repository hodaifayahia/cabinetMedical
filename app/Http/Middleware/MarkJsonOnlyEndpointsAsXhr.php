<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops JSON-only GET endpoints from becoming the session's "previous URL".
 *
 * The passkey client fetches its WebAuthn options with `fetch()` and sends only
 * `Accept: application/json` — no `X-Requested-With`. Laravel's
 * {@see StartSession::storeCurrentUrl()} records
 * a URL as the session's previous URL whenever the request is a GET, matched a
 * route, and is not flagged as XHR. These endpoints therefore looked like
 * ordinary page visits and were stored.
 *
 * Because {@see SecureResponseHeaders} sends
 * `Referrer-Policy: no-referrer`, `url()->previous()` has no referrer to fall
 * back to and returns that stored value. Every later `back()` — including the
 * automatic redirect for any ValidationException — then pointed the Inertia
 * visit at a raw JSON body, producing:
 *
 *   "All Inertia requests must receive a valid Inertia response, however a
 *    plain JSON response was received."
 *
 * Flagging these requests as XHR is the same signal Laravel already uses to
 * mean "this is not a page visit", so `storeCurrentUrl()` skips them and the
 * previous URL keeps pointing at the last real page.
 *
 * Covered by tests/Feature/Auth/PreviousUrlPoisoningTest.php.
 */
class MarkJsonOnlyEndpointsAsXhr
{
    /**
     * Routes that only ever answer JSON to a background fetch.
     *
     * @var list<string>
     */
    private const JSON_ONLY_ROUTES = [
        'passkey.confirm-options',
        'passkey.login-options',
        'passkey.registration-options',
        'well-known.passkeys',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs(self::JSON_ONLY_ROUTES)) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $next($request);
    }
}
