<?php

namespace App\Console\Commands;

use App\Services\Cabinet\CabinetSeatService;
use App\Services\Sync\SyncTransportException;
use Illuminate\Console\Command;

/**
 * Bring a local desktop's seat allowance up to date with the admin panel.
 *
 * Scheduled, so seats a platform administrator grants while the cabinet is
 * offline apply on their own once the desktop is back online. On the online
 * service, and on a desktop never linked to it, there is nothing to ask and
 * the command does nothing.
 */
class SyncCabinetSeats extends Command
{
    protected $signature = 'drclick:sync-seats';

    protected $description = 'Fetch the seats granted to this installation’s cabinet on the online service';

    public function handle(CabinetSeatService $seats): int
    {
        if (! $seats->canCheckOnline()) {
            return self::SUCCESS;
        }

        try {
            $cabinet = $seats->refresh();
        } catch (SyncTransportException $exception) {
            // Being offline is the expected state of a local installation,
            // not a fault; the seats already held stay in force.
            $exception->offline
                ? $this->components->info($exception->getMessage())
                : $this->components->error($exception->getMessage());

            return $exception->offline ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf(
            'Cabinet %d : %d sièges accordés.',
            $cabinet->getKey(),
            $cabinet->seatLimit(),
        ));

        return self::SUCCESS;
    }
}
