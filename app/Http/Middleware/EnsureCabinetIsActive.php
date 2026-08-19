<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds authenticated cabinet members out of the application until their
 * cabinet is active and their own membership has been approved.
 *
 * - Owners of pending/suspended cabinets are redirected to a status page.
 * - Members awaiting an owner's approval are redirected to a waiting page.
 *
 * Platform staff and users without a cabinet (legacy single-install accounts)
 * are unaffected. Logout and the status pages themselves are always allowed so
 * a blocked user is never trapped.
 */
class EnsureCabinetIsActive
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

        if (! $user instanceof User || $user->is_platform_admin) {
            return $next($request);
        }

        // Always allow logout and the status endpoints to avoid a redirect loop.
        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        $reason = $this->access->denialReason($user);

        if ($reason === CabinetAccessService::REASON_AWAITING_APPROVAL) {
            return redirect()->route('cabinet.awaiting-approval');
        }

        if ($reason !== null) {
            return redirect()->route('cabinet.pending');
        }

        return $next($request);
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        return $request->routeIs(
            'cabinet.pending',
            'cabinet.license.redeem',
            'cabinet.sign-out',
            'cabinet.awaiting-approval',
            'desktop.pin.enroll',
            'logout',
            'session-lock.*',
        ) || $request->is('logout');
    }
}
