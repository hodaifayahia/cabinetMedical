<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Hub\HubMode;
use Illuminate\Console\Command;

/**
 * Reports what this machine believes it is, so a Cabinet Hub can be verified
 * before the first desktop is pointed at it rather than after.
 */
class HubStatusCommand extends Command
{
    protected $signature = 'hub:status';

    protected $description = 'Show whether this installation is a Cabinet Hub and which cabinet it serves';

    public function handle(HubMode $hub): int
    {
        if (! $hub->isDeclared()) {
            $this->line('Mode: <info>hosted</info> (this installation is not a Cabinet Hub)');
            $this->newLine();
            $this->line('To make it one, set HUB_MODE=true together with HUB_ID and');
            $this->line('HUB_CABINET_ID in .env, then run: php artisan config:clear');

            return self::SUCCESS;
        }

        $this->line('Mode: <info>hub</info>');
        $this->table(['Setting', 'Value'], [
            ['Protocol version', (string) $hub->protocolVersion()],
            ['Hub ID', $hub->hubId() ?? '<comment>missing</comment>'],
            ['Bound cabinet ID', (string) ($hub->boundCabinetId() ?? '<comment>missing</comment>')],
            ['LAN hostname', $hub->hostname() ?? '(not set)'],
            ['TLS SPKI SHA-256', $hub->tlsSpkiSha256() ?? '(not set)'],
        ]);

        $authority = $hub->authority();
        $this->line('Authority: '.($authority === null
            ? '<comment>not adopted</comment>'
            : '<info>'.$authority->hub_id.'</info> at epoch '.$authority->authority_epoch));

        $reason = $hub->misconfigurationReason();

        if ($reason !== null) {
            $this->newLine();
            $this->error('This Hub is NOT usable: '.$reason);
            $this->line($this->remedy($reason));
            $this->newLine();
            $this->line('Until this is resolved the Hub refuses every request except /health,');
            $this->line('so it can never serve the wrong cabinet.');

            return self::FAILURE;
        }

        $cabinet = $hub->boundCabinet();
        $memberCount = User::query()->where('cabinet_id', $hub->boundCabinetId())->count();

        $this->newLine();
        $this->line('Serving cabinet: <info>'.$cabinet->name.'</info>');
        $this->table(['Cabinet', 'Value'], [
            ['Status', $cabinet->status->value],
            ['Activated at', $cabinet->activated_at?->toIso8601String() ?? '(not activated)'],
            ['Members', (string) $memberCount],
        ]);

        if ($cabinet->isPending()) {
            $this->newLine();
            $this->warn('This cabinet has not been activated yet. Members can sign in and');
            $this->warn('will reach the activation screen, but not the application.');
        }

        return self::SUCCESS;
    }

    private function remedy(string $reason): string
    {
        return match ($reason) {
            HubMode::MISCONFIGURED_REASON_NO_ID => 'Set HUB_ID in .env to this Hub\'s stable identifier, then run php artisan config:clear.',
            HubMode::MISCONFIGURED_REASON_NO_CABINET => 'Set HUB_CABINET_ID in .env to the id of the cabinet this Hub serves, then run php artisan config:clear.',
            HubMode::MISCONFIGURED_REASON_CABINET_UNKNOWN => 'HUB_CABINET_ID names a cabinet that does not exist in this database. Restore the correct database or correct the binding; do not point it at a different cabinet.',
            HubMode::MISCONFIGURED_REASON_NOT_ADOPTED => 'No Hub has been adopted as the write authority for this cabinet yet. Run php artisan hub:adopt --confirm.',
            HubMode::MISCONFIGURED_REASON_DISPLACED => 'The authority for this cabinet belongs to another Hub. If that Hub is permanently out of service, take over with php artisan hub:adopt --confirm. If it is still running, STOP: two machines would be writing to two databases for one cabinet.',
            default => 'Review the hub configuration in config/hub.php.',
        };
    }
}
