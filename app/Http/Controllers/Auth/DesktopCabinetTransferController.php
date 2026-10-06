<?php

namespace App\Http\Controllers\Auth;

use App\CabinetTransfer\OnlineCabinetImporter;
use App\CabinetTransfer\TransferState;
use App\Http\Controllers\Controller;
use App\Jobs\ImportCabinetFromOnlineService;
use App\Services\MachineFingerprintService;
use App\Support\ClinicalWorkstation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Progress of a transfer of an online cabinet's records to this PC, and its
 * last step: once the copy is verified here, the doctor removes the copy
 * left on the online service.
 */
class DesktopCabinetTransferController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        $state = $this->state();

        return Inertia::render('auth/DesktopCabinetTransfer', [
            'transfer' => $state->publicView(),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->state()->publicView());
    }

    public function retry(OnlineCabinetImporter $importer): RedirectResponse
    {
        $state = $this->state();
        abort_unless($state->status() === TransferState::FAILED, 409);

        $importer->reset();
        $state->update([
            'status' => TransferState::RUNNING,
            'phase' => 'manifest',
            'message' => 'Préparation du transfert…',
            'error' => null,
            'manifest' => null,
            'file_index' => 0,
            'table_index' => 0,
            'after' => 0,
            'copied_rows' => 0,
            'copied_files' => 0,
        ]);
        ImportCabinetFromOnlineService::dispatch();

        return redirect()->route('desktop.transfer.show');
    }

    public function cancel(OnlineCabinetImporter $importer): RedirectResponse
    {
        $state = $this->state();
        abort_unless($state->status() === TransferState::FAILED, 409);

        $importer->reset();
        TransferState::clear();

        return redirect()->route('home');
    }

    public function purge(Request $request, HttpFactory $http, MachineFingerprintService $fingerprint): RedirectResponse
    {
        $state = $this->state();
        abort_unless($state->status() === TransferState::IMPORTED, 409);

        $request->validate([
            'confirmation' => ['required', 'string', 'in:SUPPRIMER'],
        ], [
            'confirmation.in' => 'Tapez SUPPRIMER en majuscules pour confirmer.',
        ]);

        try {
            $response = $http
                ->baseUrl((string) $state->get('endpoint'))
                ->withToken($state->token())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(120)
                ->withoutRedirecting()
                ->post('/api/v1/cabinet-transfer/complete', [
                    'fingerprint' => (string) ($state->get('manifest')['fingerprint'] ?? ''),
                    'installation_id' => $fingerprint->installationId(),
                ]);
        } catch (ConnectionException|RuntimeException) {
            $state->update(['purge_error' => 'Le service en ligne est injoignable. Vérifiez la connexion Internet puis réessayez.']);

            return redirect()->route('desktop.transfer.show');
        }

        if (! $response->successful()) {
            $state->update([
                'purge_error' => (string) ($response->json('message')
                    ?? 'Le service en ligne a refusé la suppression (code '.$response->status().'). Réessayez plus tard.'),
            ]);

            return redirect()->route('desktop.transfer.show');
        }

        $state->update([
            'status' => TransferState::PURGED,
            'transferred_at' => $response->json('transferred_at'),
            'purge_error' => null,
        ]);

        return redirect()->route('desktop.transfer.show');
    }

    private function state(): TransferState
    {
        abort_if(ClinicalWorkstation::isOnlineService(), 404);

        $state = TransferState::current();
        abort_if($state === null, 404);

        return $state;
    }
}
