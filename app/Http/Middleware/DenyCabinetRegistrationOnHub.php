<?php

namespace App\Http\Middleware;

use App\Services\Hub\HubMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a Cabinet Hub from provisioning a second cabinet.
 *
 * Registration creates a brand-new cabinet, which on a Hub would produce a
 * tenant the machine is not the authority for and that nothing will ever
 * activate or synchronise — ADR-002 invariants 1 and 4. Staff who need an
 * account on a Hub join the bound cabinet through /join instead, which works
 * with no Internet connection.
 */
class DenyCabinetRegistrationOnHub
{
    private const MESSAGE = 'Ce Hub gère déjà un cabinet et ne peut pas en créer un second. Pour obtenir un accès, demandez à rejoindre le cabinet existant.';

    public function __construct(private readonly HubMode $hub) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Self-scoping: this sits in the web group because Fortify's routes
        // cannot be reliably decorated at boot time.
        if (! $request->routeIs('register', 'register.store')) {
            return $next($request);
        }

        if (! $this->hub->isDeclared()) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return redirect()->route('cabinet.join')->with('status', self::MESSAGE);
        }

        throw ValidationException::withMessages([
            'cabinet_name' => self::MESSAGE,
        ]);
    }
}
