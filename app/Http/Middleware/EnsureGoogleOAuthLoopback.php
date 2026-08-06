<?php

namespace App\Http\Middleware;

use App\Services\GoogleDriveOAuthException;
use App\Services\GoogleOAuthLoopbackOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGoogleOAuthLoopback
{
    public function __construct(private readonly GoogleOAuthLoopbackOrigin $origin) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->origin->assertCallbackRequest($request);
        } catch (GoogleDriveOAuthException) {
            return response('', 404, [
                'Cache-Control' => 'no-store, private, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
