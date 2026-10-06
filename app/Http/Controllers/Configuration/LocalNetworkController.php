<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Services\Cabinet\CabinetSeatService;
use App\Services\LanHostBoundary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuration › Réseau local: make this desktop the cabinet's "poste
 * principal" so the assistant's PC works on the same database (ADR-005).
 *
 * The switch itself is native (the Tauri shell starts the LAN listener); this
 * page only supplies what the server knows: whether it runs inside the
 * desktop, whether the current visitor is already another PC of the cabinet,
 * and how many accounts the cabinet may still add.
 */
class LocalNetworkController extends Controller
{
    public function __invoke(Request $request, CabinetSeatService $seats): Response
    {
        $cabinet = $request->user()->cabinet;

        return Inertia::render('configuration/LocalNetwork', [
            'desktopSupervised' => (bool) config('medismart.runtime.desktop_supervised', false),
            // True when this page is shown on a poste secondaire, through the
            // poste principal's LAN listener.
            'lanClient' => LanHostBoundary::isLanClientRequest($request),
            'seats' => $cabinet instanceof Cabinet ? $seats->summary($cabinet) : null,
            'staffUrl' => route('app.staff.index'),
        ]);
    }
}
