<?php

namespace App\Http\Middleware;

use App\Services\LanUploadBoundary;
use App\Services\RemoteUploadBoundary;
use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceRemoteUploadBoundary extends TrustProxies
{
    public function __construct(
        private readonly RemoteUploadBoundary $boundary,
        private readonly LanUploadBoundary $lanBoundary,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = parent::handle(
            $request,
            fn (Request $trustedRequest): Response => $this->enforce($trustedRequest, $next),
        );

        return $response;
    }

    private function enforce(Request $request, Closure $next): Response
    {
        if (! $this->boundary->enforcementEnabled()) {
            return $next($request);
        }

        $hostKind = $this->boundary->hostKind($request);
        $lanMatches = $this->lanBoundary->enabled()
            && $this->lanBoundary->authorityMatches($request);

        // An authority shared by two audiences is configuration ambiguity,
        // never a reason to choose the more permissive interpretation.
        if ($lanMatches && ($hostKind !== null
            || $this->boundary->configuredAuthorityMatches($request))) {
            return $this->notFound();
        }

        if ($hostKind === null && $lanMatches) {
            $hostKind = 'lan';
        }

        if ($hostKind === null) {
            return $this->notFound();
        }

        $request->attributes->set(RemoteUploadBoundary::REQUEST_ATTRIBUTE, [
            'route_set' => RemoteUploadBoundary::ROUTE_SET,
            'upload_routes_only' => true,
            'exact_host_enforced' => true,
            'forwarded_https_enforced' => true,
            'local_tokens_rejected_on_remote_host' => true,
        ]);
        $request->attributes->set(LanUploadBoundary::REQUEST_ATTRIBUTE, [
            'route_set' => LanUploadBoundary::ROUTE_SET,
            'upload_routes_only' => true,
            'exact_origin_enforced' => true,
            'explicit_high_port_enforced' => true,
            'direct_private_peer_enforced' => true,
            'forwarding_headers_rejected' => true,
            'local_tokens_bound_to_lan_origin' => true,
        ]);

        if ($hostKind === 'local' && ! $this->boundary->isDirectLocalRequest($request)) {
            return $this->notFound();
        }

        if ($hostKind === 'remote') {
            if (! $this->boundary->isVerifiedRemoteProxyRequest($request)
                || ! $this->boundary->remoteRouteAllowed($request)) {
                return $this->notFound();
            }

            // The per-run launcher key is never an authenticator on the
            // public side of the loopback proxy boundary.
            $request->headers->remove('X-MediSmart-Health-Key');
        }

        if ($hostKind === 'lan') {
            if (! $this->lanBoundary->isDirectLanRequest($request)
                || ! $this->lanBoundary->routeAllowed($request)) {
                return $this->notFound();
            }

            // The native per-run diagnostics key belongs exclusively to the
            // exact loopback administration origin.
            $request->headers->remove('X-MediSmart-Health-Key');
        }

        $response = $next($request);

        if ($hostKind === 'remote') {
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie->withSecure());
            }
        }

        return $response;
    }

    private function notFound(): Response
    {
        return response('', 404, [
            'Cache-Control' => 'no-store, private, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
