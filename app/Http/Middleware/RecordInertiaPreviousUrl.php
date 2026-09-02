<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records an Inertia page visit as the session's "previous URL".
 *
 * {@see StartSession::storeCurrentUrl()} stores a URL only when the request is
 * a GET, matched a route, and is *not* flagged as XHR. The Inertia client sends
 * `X-Requested-With: XMLHttpRequest` on every visit, so once the SPA takes over
 * navigation nothing is ever stored again — the value stays frozen at the last
 * full page load, which after signing in is the dashboard.
 *
 * That value is what `back()` returns, because {@see SecureResponseHeaders}
 * sends `Referrer-Policy: no-referrer` and leaves `url()->previous()` with no
 * referrer to prefer. Around eighty `return back()` calls across the
 * controllers therefore sent the user to the dashboard instead of the screen
 * they were on: confirming or cancelling an appointment, or running a mobile
 * sync, did its work and then threw the page away, which read as the action
 * having failed.
 *
 * Recording real page visits here restores the behaviour `back()` would have in
 * a non-SPA request cycle. It is deliberately the mirror image of
 * {@see MarkJsonOnlyEndpointsAsXhr}, which suppresses storage for background
 * fetches that are not page visits at all; the two never overlap, because those
 * endpoints carry no `X-Inertia` header.
 *
 * Covered by tests/Feature/Inertia/PreviousUrlForInertiaVisitsTest.php.
 */
class RecordInertiaPreviousUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isPageVisit($request, $response)) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }

    private function isPageVisit(Request $request, Response $response): bool
    {
        // A redirect is not somewhere the user can be sent back to, and an
        // error page would strand them; only a rendered page counts.
        return $request->isMethod('GET')
            && $request->hasHeader('X-Inertia')
            && $request->route() !== null
            && $request->hasSession()
            && $response->isSuccessful();
    }
}
