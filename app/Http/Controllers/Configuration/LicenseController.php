<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LicenseActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

final class LicenseController extends Controller
{
    public function store(
        Request $request,
        LicenseActivationService $activation,
    ): RedirectResponse {
        abort_unless($activation->status()['configured'], 503, 'License activation is not configured.');
        $data = $request->validate([
            'serial' => ['required', 'string', 'min:14', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $activation->activate($data['serial'], $user);
        } catch (Throwable) {
            return back()->withErrors([
                'license' => 'Activation impossible. Vérifiez le numéro, la connexion et l’état de l’abonnement.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Licence activée et signature vérifiée.',
        ]);

        return back();
    }

    public function refresh(
        Request $request,
        LicenseActivationService $activation,
    ): RedirectResponse {
        abort_unless($activation->status()['refresh_configured'], 503, 'License refresh is not configured.');

        /** @var User $user */
        $user = $request->user();

        try {
            $activation->refresh($user);
        } catch (Throwable) {
            return back()->withErrors([
                'license_refresh' => 'Vérification impossible. La licence locale reste inchangée; réessayez lorsque la connexion est disponible.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'État de la licence actualisé et signature vérifiée.',
        ]);

        return back();
    }

    public function destroy(
        Request $request,
        LicenseActivationService $activation,
    ): RedirectResponse {
        abort_unless($activation->status()['deactivation_configured'], 503, 'License deactivation is not configured.');

        /** @var User $user */
        $user = $request->user();

        try {
            $activation->deactivate($user);
        } catch (Throwable) {
            return back()->withErrors([
                'license_deactivation' => 'Désactivation impossible. Rien n’a été supprimé localement; vérifiez la connexion puis réessayez.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Licence désactivée pour cette installation.',
        ]);

        return back();
    }
}
