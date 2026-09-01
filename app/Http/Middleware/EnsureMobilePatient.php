<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts patient-only mobile endpoints to accounts holding the Patient
 * role. Staff and platform tokens are rejected with a machine-readable
 * reason code.
 */
class EnsureMobilePatient
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasRole(RoleName::PATIENT->value)) {
            return response()->json([
                'message' => 'Cette action est réservée aux comptes patients.',
                'reason' => 'patient_role_required',
            ], 403);
        }

        return $next($request);
    }
}
