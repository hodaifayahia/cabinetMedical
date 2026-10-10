<?php

namespace App\Http\Controllers\Configuration;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\DemoCabinetData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Configuration › Données de démonstration: fill the cabinet with a
 * realistic week of activity to try Drclick, then remove it again. Only the
 * super administrator of an installed desktop may do either: the hosted
 * service never holds clinical data, and an assistant must not be able to
 * add or wipe patients.
 */
class DemoDataController extends Controller
{
    public function show(Request $request, DemoCabinetData $demo): Response
    {
        $this->authorizeDemoData($request);

        return Inertia::render('configuration/DemoData', [
            'hasDemoData' => $demo->hasData(),
            'counts' => $demo->counts(),
        ]);
    }

    public function store(Request $request, DemoCabinetData $demo): RedirectResponse
    {
        $user = $this->authorizeDemoData($request);

        try {
            $counts = $demo->fill($user);
        } catch (Throwable $exception) {
            Log::error('Demo data could not be created.', ['error' => $exception->getMessage()]);

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Les données de démonstration n’ont pas pu être créées. Rien n’a été ajouté.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Données de démonstration ajoutées : %d patients, %d consultations, %d rendez-vous.',
            $counts['patients'],
            $counts['consultations'],
            $counts['appointments'],
        )]);

        return back();
    }

    public function destroy(Request $request, DemoCabinetData $demo): RedirectResponse
    {
        $this->authorizeDemoData($request);

        try {
            $removed = $demo->remove();
        } catch (Throwable $exception) {
            Log::error('Demo data could not be removed.', ['error' => $exception->getMessage()]);

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Les données de démonstration n’ont pas pu être retirées, sans doute parce que des documents ou dossiers ont été ajoutés à ces patients. Rien n’a été supprimé.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Données de démonstration retirées : %d patients, %d consultations, %d rendez-vous.',
            $removed['patients'],
            $removed['consultations'],
            $removed['appointments'],
        )]);

        return back();
    }

    public static function allows(?User $user): bool
    {
        return $user instanceof User
            && $user->cabinet_id !== null
            && (bool) config('medismart.runtime.desktop_supervised', false)
            && $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value);
    }

    private function authorizeDemoData(Request $request): User
    {
        $user = $request->user();

        abort_unless(self::allows($user), 403);

        return $user;
    }
}
