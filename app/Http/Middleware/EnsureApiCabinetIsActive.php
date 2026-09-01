<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of EnsureCabinetIsActive. Instead of redirecting, a blocked
 * member receives a 403 JSON response carrying a French explanation and a
 * machine-readable reason code. Shares its eligibility rules with the web
 * middleware through CabinetAccessService.
 */
class EnsureApiCabinetIsActive
{
    public function __construct(
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Patient accounts never reach cabinet-scoped endpoints: they hold
            // no cabinet and their global scopes would be inert.
            if ($user->hasRole(RoleName::PATIENT->value)) {
                return response()->json([
                    'message' => "Ce compte patient ne peut pas accéder à l'espace du cabinet.",
                    'reason' => 'patient_token_forbidden',
                    'status' => 'forbidden',
                ], 403);
            }

            $reason = $this->access->denialReason($user);

            if ($reason !== null) {
                return response()->json([
                    'message' => $this->access->denialMessage($user),
                    'reason' => $reason,
                    'status' => $this->access->denialStatus($user),
                ], 403);
            }
        }

        return $next($request);
    }
}
