<?php

namespace App\Jobs;

use App\CabinetTransfer\OnlineCabinetImporter;
use App\CabinetTransfer\TransferState;
use App\Licensing\CabinetEntitlementVerifier;
use App\Licensing\CloudActivation;
use App\Licensing\DesktopLicenseActivator;
use App\Models\Cabinet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Copies an online cabinet onto this PC in steps of about 40 seconds (the
 * desktop worker stops a job after 60): each run advances the transfer and
 * queues the next one until every row and file is in place and verified.
 * Then the cabinet is activated here and linked for the mobile app.
 */
class ImportCabinetFromOnlineService implements ShouldQueue
{
    use Queueable;

    public const STEP_SECONDS = 40;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(
        OnlineCabinetImporter $importer,
        DesktopLicenseActivator $activator,
        CabinetEntitlementVerifier $verifier,
    ): void {
        $state = TransferState::current();

        if ($state === null || $state->status() !== TransferState::RUNNING) {
            return;
        }

        try {
            if (! $importer->step($state, microtime(true) + self::STEP_SECONDS)) {
                self::dispatch();

                return;
            }

            $state->update(['phase' => 'activate', 'message' => 'Activation du cabinet sur ce poste…']);
            $activator->adoptTransferredCabinet(
                Cabinet::query()->sole(),
                new CloudActivation(
                    endpoint: (string) $state->get('endpoint'),
                    envelope: (string) $state->get('envelope'),
                    entitlement: $verifier->verify((string) $state->get('envelope')),
                    cabinet: (array) $state->get('remote_cabinet', []),
                    token: $state->token(),
                    accountEmail: $state->get('account_email'),
                    accountCabinetName: $state->get('account_cabinet_name'),
                ),
            );

            $state->update([
                'status' => TransferState::IMPORTED,
                'phase' => 'done',
                'message' => 'Les dossiers du cabinet sont sur ce poste.',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Cabinet transfer from the online service failed.', ['exception' => $exception]);

            $state->update([
                'status' => TransferState::FAILED,
                'error' => $this->message($exception),
            ]);
        }
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'L’activation du cabinet a été refusée.';
        }

        return $exception instanceof RuntimeException && ! str_contains($exception->getMessage(), 'SQLSTATE')
            ? $exception->getMessage()
            : 'Le transfert s’est arrêté sur une erreur inattendue. Réessayez ; si cela se reproduit, contactez le support Drclick.';
    }
}
