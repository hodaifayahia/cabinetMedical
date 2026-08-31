<?php

namespace App\Console\Commands;

use App\Licensing\CabinetEntitlementIssuer;
use App\Licensing\CabinetEntitlementVerifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Control-plane command that mints the signed entitlement a Cabinet Hub is
 * activated with offline.
 *
 * The customer receives the resulting file by e-mail or on a USB stick and
 * applies it with `php artisan hub:activate`. Nothing about that path touches
 * the network, which is the point.
 */
class IssueCabinetEntitlementCommand extends Command
{
    protected $signature = 'license:issue-entitlement
        {--owner= : E-mail address of the cabinet owner the entitlement is for}
        {--plan=trial : trial or lifetime}
        {--days=7 : Days a trial runs for; ignored for a lifetime entitlement}
        {--hub= : Optional Hub id to bind the entitlement to a single Hub}
        {--key= : Path to the RSA private signing key (PEM)}
        {--passphrase= : Passphrase for the signing key, if it has one}
        {--out= : Write the entitlement here instead of printing it}';

    protected $description = 'Sign a cabinet entitlement so a Hub can be activated with no Internet connection';

    public function handle(
        CabinetEntitlementIssuer $issuer,
        CabinetEntitlementVerifier $verifier,
    ): int {
        $owner = (string) $this->option('owner');
        $key = (string) $this->option('key');

        if ($owner === '' || $key === '') {
            $this->error('--owner and --key are both required.');

            return self::FAILURE;
        }

        try {
            $envelope = $issuer->issue(
                signingKeyPath: $key,
                ownerEmail: $owner,
                plan: (string) $this->option('plan'),
                trialDays: max(1, (int) $this->option('days')),
                hubId: $this->option('hub') === null ? null : (string) $this->option('hub'),
                passphrase: $this->option('passphrase') === null ? null : (string) $this->option('passphrase'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Issuing and verifying use separate code paths, so round-tripping here
        // catches a key mismatch on the control plane rather than in a cabinet
        // with no Internet and no way to diagnose it.
        if ($verifier->isConfigured()) {
            try {
                $entitlement = $verifier->verify($envelope);
                $this->info('Verified against the configured public key.');
                $this->table(['Entitlement', 'Value'], [
                    ['Id', $entitlement->entitlementId],
                    ['Owner', $entitlement->ownerEmail],
                    ['Plan', $entitlement->plan],
                    ['Expires', $entitlement->expiresAt?->toIso8601String() ?? 'never (lifetime)'],
                    ['Bound to Hub', $entitlement->hubId ?? 'any Hub of this customer'],
                ]);
            } catch (Throwable $exception) {
                $this->error('The entitlement was signed but does NOT verify against the configured public key: '.$exception->getMessage());
                $this->line('The signing key and the deployed public key are not a pair. Do not send this file.');

                return self::FAILURE;
            }
        } else {
            $this->warn('No verification public key is configured here, so the entitlement was not round-tripped.');
            $this->warn('Verify it against the key the customer actually has before sending it.');
        }

        $out = $this->option('out');

        if ($out === null) {
            $this->newLine();
            $this->line($envelope);

            return self::SUCCESS;
        }

        if (file_put_contents((string) $out, $envelope) === false) {
            $this->error('Could not write '.$out.'.');

            return self::FAILURE;
        }

        $this->info('Written to '.$out);

        return self::SUCCESS;
    }
}
