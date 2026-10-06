<?php

namespace App\Http\Middleware;

use App\Support\ClinicalWorkstation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps patient records off the online service: on drclickdz.com the
 * clinical screens send the cabinet to its online space, which explains
 * that records live in the desktop app. Configuration and staff screens
 * hold no patient data and stay available.
 */
class EnsureClinicalWorkstation
{
    /** Route-name prefixes that stay available online. */
    private const ONLINE_ROUTES = [
        'app.configuration.',
        'app.staff.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (ClinicalWorkstation::clinicalScreensOpen()) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();

        if (Str::startsWith($name, self::ONLINE_ROUTES)) {
            return $next($request);
        }

        $message = 'Les dossiers patients sont gérés dans l’application Drclick installée sur le PC du cabinet.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('online-space');
    }
}
