<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Services\Cabinet\CabinetSeatService;
use App\Services\Sync\OnlineServiceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuration › Service en ligne: link this desktop to the hosted service
 * so it receives the seats bought in the admin panel, syncs appointments
 * with the mobile application and can use the AI assistant.
 */
class OnlineServiceController extends Controller
{
    public function edit(Request $request, OnlineServiceLink $link, CabinetSeatService $seats): Response
    {
        $cabinet = $request->user()->cabinet;

        return Inertia::render('configuration/OnlineService', [
            'available' => $link->isAvailable(),
            'link' => $link->status($cabinet instanceof Cabinet ? $cabinet : null),
            'seats' => $cabinet instanceof Cabinet ? $seats->summary($cabinet) : null,
            'sync' => $cabinet instanceof Cabinet ? $link->syncStatus($cabinet) : null,
            'canSyncNow' => $request->user()->can('create', Appointment::class),
            // Pre-fills the e-mail: the online account must be the owner's.
            'ownerEmail' => $cabinet instanceof Cabinet ? $cabinet->owner?->email : null,
        ]);
    }

    public function store(Request $request, OnlineServiceLink $link): RedirectResponse
    {
        abort_unless($link->isAvailable(), 403, 'Seul un poste installé se relie au service en ligne.');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $cabinet = $this->cabinetOf($request);

        if ($link->isLinked()) {
            throw ValidationException::withMessages([
                'endpoint' => $link->isLinkedFor($cabinet)
                    ? 'Ce poste est déjà relié. Déliez-le avant de le relier à un autre compte.'
                    : 'Ce poste est déjà relié au service en ligne pour un autre cabinet de cet ordinateur.',
            ]);
        }

        $seatLimit = $link->link($cabinet, $data['endpoint'], $data['email'], $data['password']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Poste relié au service en ligne. Votre cabinet dispose de '.$seatLimit.' sièges. '
                .'Les rendez-vous de l’application mobile se synchronisent désormais automatiquement.',
        ]);

        return to_route('app.configuration.online-service.edit');
    }

    public function destroy(Request $request, OnlineServiceLink $link): RedirectResponse
    {
        $cabinet = $this->cabinetOf($request);

        // Only the cabinet the link was made for may undo it.
        abort_if(
            $link->isLinked() && ! $link->isLinkedFor($cabinet),
            403,
            'Ce poste est relié au service en ligne pour un autre cabinet.',
        );

        $link->unlink($cabinet);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Ce poste n’est plus relié au service en ligne.',
        ]);

        return to_route('app.configuration.online-service.edit');
    }

    private function cabinetOf(Request $request): Cabinet
    {
        $cabinet = $request->user()->cabinet;

        abort_unless($cabinet instanceof Cabinet, 403, 'Ce compte n’est rattaché à aucun cabinet.');

        return $cabinet;
    }
}
