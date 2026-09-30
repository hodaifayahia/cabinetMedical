<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Sync\MobileAppointmentSynchroniser;
use App\Services\Sync\MobileSyncSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "synchroniser avec l'application mobile" action.
 *
 * A local-first installation works offline; this is the one deliberate moment
 * where it reaches the internet, and it only ever runs because a clinician
 * pressed the button.
 */
class MobileSyncController extends Controller
{
    public function __invoke(
        Request $request,
        MobileAppointmentSynchroniser $synchroniser,
        MobileSyncSettings $settings,
    ): RedirectResponse {
        // Sync writes appointments, so it requires the same permission as
        // creating one; a read-only account cannot trigger it.
        $this->authorize('create', Appointment::class);

        if (! $settings->isConfigured()) {
            return back()->with(
                'error',
                "La synchronisation mobile n'est pas configurée sur ce poste. Reliez-le au service en ligne dans Configuration › Service en ligne.",
            );
        }

        $cabinetId = $request->user()?->cabinet_id;

        if ($cabinetId === null) {
            return back()->with('error', "Ce compte n'est rattaché à aucun cabinet.");
        }

        $report = $synchroniser->synchronise((int) $cabinetId);

        if ($report->failed()) {
            return back()->with($report->offline ? 'warning' : 'error', $report->error);
        }

        if ($report->wasQuiet()) {
            return back()->with('success', 'Tout est déjà à jour.');
        }

        return back()->with('success', $this->summary($report->toArray()));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summary(array $report): string
    {
        $parts = [];

        if ($report['created'] > 0) {
            $parts[] = sprintf('%d rendez-vous ajouté(s)', $report['created']);
        }

        if ($report['updated'] > 0) {
            $parts[] = sprintf('%d mis à jour', $report['updated']);
        }

        if ($report['deleted'] > 0) {
            $parts[] = sprintf('%d annulé(s)', $report['deleted']);
        }

        if ($report['pushed'] > 0) {
            $parts[] = sprintf('%d envoyé(s) vers le mobile', $report['pushed']);
        }

        $summary = 'Synchronisation terminée : '.implode(', ', $parts).'.';

        if ($report['conflicts'] > 0) {
            // Silence here would leave two diverging versions of a patient's
            // appointment with nobody aware of it.
            $summary .= sprintf(
                ' %d rendez-vous modifié(s) des deux côtés : vérifiez-les, la version de ce poste a été conservée.',
                $report['conflicts'],
            );
        }

        return $summary;
    }
}
