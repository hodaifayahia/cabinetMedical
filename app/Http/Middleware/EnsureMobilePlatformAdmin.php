<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the platform back-office endpoints of the mobile API to accounts
 * flagged is_platform_admin.
 *
 * Superadmins are provisioned by the `platform:provision-superadmin` console
 * command only, never over HTTP, so this gate reads a column no request body
 * is ever allowed to write. Doctor, reception and patient tokens are rejected
 * with a machine-readable reason code — a 403, never a 404, so the client can
 * tell "you may not" apart from "it is not there".
 */
class EnsureMobilePlatformAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_platform_admin !== true) {
            return response()->json([
                'message' => 'Cet espace est réservé aux administrateurs de la plateforme.',
                'reason' => 'platform_admin_required',
                'status' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
