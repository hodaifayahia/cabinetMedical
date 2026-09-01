<?php

namespace App\Console\Commands;

use App\Models\HubAuthority;
use App\Services\Hub\HubAdoptionService;
use App\Services\Hub\HubMode;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives this Cabinet Hub authority over its cabinet — at installation, and
 * again when a Hub is replaced after a failure.
 *
 * This is the recovery path ADR-003 requires. It runs entirely on the LAN with
 * no control plane, so a cabinet that has never had Internet can bring a
 * replacement appliance into service the same afternoon the old one died.
 */
class HubAdoptCommand extends Command
{
    protected $signature = 'hub:adopt {--confirm : Perform the adoption instead of only describing it}';

    protected $description = 'Adopt this Hub as its cabinet\'s clinical write authority, or take over from a failed Hub';

    public function handle(HubMode $hub, HubAdoptionService $adoption): int
    {
        if (! $hub->isDeclared()) {
            $this->error('This installation is not a Cabinet Hub. Set HUB_MODE=true first.');

            return self::FAILURE;
        }

        if ($hub->hubId() === null || $hub->boundCabinetId() === null) {
            $this->error('Set HUB_ID and HUB_CABINET_ID before adopting, then run php artisan config:clear.');

            return self::FAILURE;
        }

        $cabinet = $hub->boundCabinet();

        if ($cabinet === null) {
            $this->error('HUB_CABINET_ID names a cabinet that is not in this database.');
            $this->line('Restore the correct backup. Do not point this Hub at a different cabinet.');

            return self::FAILURE;
        }

        $current = $hub->authority();
        $provisioning = $current === null;

        $this->line('Cabinet:   <info>'.$cabinet->name.'</info> (id '.$cabinet->getKey().')');
        $this->line('This Hub:  <info>'.$hub->hubId().'</info>');

        if ($provisioning) {
            $this->line('Authority: <comment>not yet held by any Hub</comment>');
            $this->newLine();
            $this->line('Adopting will make this Hub the cabinet\'s write authority at epoch 1.');
        } elseif ($current->isHeldBy($hub->hubId())) {
            $this->info('This Hub already holds authority at epoch '.$current->authority_epoch.'. Nothing to do.');

            return self::SUCCESS;
        } else {
            $this->line('Authority: <comment>held by '.$current->hub_id.'</comment> at epoch '.$current->authority_epoch);
            $this->newLine();
            $this->warn('This will TAKE OVER authority from '.$current->hub_id.' and raise the epoch to '.($current->authority_epoch + 1).'.');
            $this->warn('Only do this if that Hub is permanently out of service. If it is still');
            $this->warn('running on the same network, two machines will be writing to two');
            $this->warn('different databases for the same cabinet, and the records will diverge.');
        }

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->line('Nothing has been changed. Re-run with --confirm to proceed.');

            return self::SUCCESS;
        }

        try {
            $authority = $adoption->adopt();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('This Hub now holds authority at epoch '.$authority->authority_epoch.'.');

        if ($authority->adopted_reason === HubAuthority::REASON_REPLACEMENT) {
            $this->line('Hub '.$authority->previous_hub_id.' has been displaced and must not be brought back online.');
        }

        return self::SUCCESS;
    }
}
