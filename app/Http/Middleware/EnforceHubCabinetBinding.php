<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Hub\HubMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a Cabinet Hub to the single cabinet it was bound to.
 *
 * ADR-002 invariant 4: "A Hub is bound to exactly one cabinet and rejects
 * another cabinet's users, data, licence, backups, and synchronization
 * stream." Invariant 7 adds that a Hub which cannot establish its authority
 * fails closed rather than guessing.
 *
 * The tenant scope in BelongsToCabinet already keeps two cabinets' records
 * apart inside one database. This is the coarser boundary in front of it: on a
 * Hub, a member of another cabinet is not a user with limited visibility, they
 * are a user on the wrong machine entirely.
 */
class EnforceHubCabinetBinding
{
    private const WRONG_CABINET_MESSAGE = 'Ce poste est relié au Hub d’un autre cabinet. Utilisez le Hub de votre cabinet ou la version hébergée.';

    private const MISCONFIGURED_MESSAGE = 'Ce Hub n’est relié à aucun cabinet valide et ne peut pas être utilisé. Contactez le support Drclick.';

    public function __construct(private readonly HubMode $hub) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hub->isDeclared()) {
            return $next($request);
        }

        // Diagnostics stay reachable on a broken Hub; that is how an operator
        // finds out what is wrong with it.
        if ($request->routeIs('health') || $request->is('health', 'up')) {
            return $next($request);
        }

        if ($this->hub->misconfigurationReason() !== null) {
            return $this->refuse($request, self::MISCONFIGURED_MESSAGE, 503);
        }

        $user = $request->user();

        if (! $user instanceof User || $this->hub->serves($user)) {
            return $next($request);
        }

        AuditLog::record('hub.foreign_cabinet_rejected', $user, [
            'hub_id' => $this->hub->hubId(),
            'bound_cabinet_id' => $this->hub->boundCabinetId(),
            'user_cabinet_id' => $user->cabinet_id,
        ], $user->getKey());

        // The session belongs to a cabinet this machine does not serve, so it
        // is ended rather than merely blocked. Leaving it open would let every
        // subsequent request re-enter this branch.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->refuse($request, self::WRONG_CABINET_MESSAGE, 403);
    }

    private function refuse(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        if ($status === 503) {
            abort(503, $message);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
