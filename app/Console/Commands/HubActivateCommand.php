<?php

namespace App\Console\Commands;

use App\Licensing\CabinetEntitlementVerifier;
use App\Services\CabinetFulfillmentService;
use App\Services\Hub\HubMode;
use Illuminate\Console\Command;
use Throwable;

/**
 * Applies a signed cabinet entitlement on a Cabinet Hub with no Internet
 * connection.
 *
 * The hosted flow e-mails a one-time code that is matched against a row only
 * the control plane can create. A Hub that has never been online has no such
 * row, so the customer is handed a signed entitlement file instead — on a USB
 * stick, or by e-mail read on another machine — and applies it here.
 */
class HubActivateCommand extends Command
{
    protected $signature = 'hub:activate {file : Path to the signed entitlement file}';

    protected $description = 'Activate this Hub\'s cabinet from a signed entitlement file, offline';

    public function handle(
        CabinetEntitlementVerifier $verifier,
        CabinetFulfillmentService $fulfillment,
        HubMode $hub,
    ): int {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("No readable entitlement file at {$path}.");

            return self::FAILURE;
        }

        if (! $verifier->isConfigured()) {
            $this->error('No licence verification public key is configured, so nothing can be trusted.');
            $this->line('Set MEDISMART_LICENSE_PUBLIC_KEY_PATH and try again.');

            return self::FAILURE;
        }

        $envelope = (string) file_get_contents($path);

        try {
            $entitlement = $verifier->verify($envelope);
        } catch (Throwable $exception) {
            $this->error('This entitlement was refused: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Entitlement', 'Value'], [
            ['Cabinet owner', $entitlement->ownerEmail],
            ['Plan', $entitlement->plan],
            ['Issued at', $entitlement->issuedAt->toIso8601String()],
            ['Expires at', $entitlement->expiresAt?->toIso8601String() ?? 'never (lifetime)'],
            ['Bound to Hub', $entitlement->hubId ?? 'any Hub of this customer'],
        ]);

        try {
            $cabinet = $fulfillment->activateFromOfflineEntitlement($entitlement, $hub->hubId());
        } catch (Throwable $exception) {
            $this->error('Activation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Cabinet \"{$cabinet->name}\" is now active.");

        if (! $entitlement->isLifetime()) {
            $this->line('The licence expires on '.$entitlement->expiresAt->toDayDateTimeString().'.');
        }

        return self::SUCCESS;
    }
}
