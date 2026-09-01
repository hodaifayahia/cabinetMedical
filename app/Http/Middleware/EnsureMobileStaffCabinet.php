<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the staff-mobile endpoints to genuine cabinet members. The
 * cabinet gate (EnsureApiCabinetIsActive) deliberately lets platform admins
 * and legacy null-cabinet accounts through for the historical staff API, but
 * the staff-mobile controllers rely on the BelongsToCabinet global scope —
 * which is inert for both classes of user, so every implicitly-scoped query
 * would resolve against an arbitrary tenant. Phase 1 gives admins no mobile
 * endpoints, and unscoped accounts have no cabinet to act on: both are
 * rejected with a machine-readable reason code.
 */
class EnsureMobileStaffCabinet
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_platform_admin || $user->cabinet_id === null) {
            return response()->json([
                'message' => "Cet espace est réservé aux membres d'un cabinet.",
                'reason' => 'cabinet_membership_required',
                'status' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
