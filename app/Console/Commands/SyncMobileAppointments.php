<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Services\Sync\MobileAppointmentSynchroniser;
use App\Services\Sync\MobileSyncSettings;
use Illuminate\Console\Command;

/**
 * Run a two-way appointment sync from the terminal or the scheduler.
 *
 * The desktop UI calls the same synchroniser, so a scheduled run and a
 * clinician pressing the button do exactly the same work.
 */
class SyncMobileAppointments extends Command
{
    protected $signature = 'drclick:sync-appointments
        {--cabinet= : Restrict the run to one cabinet id}';

    protected $description = 'Exchange appointments with the online service used by the mobile application';

    public function handle(
        MobileAppointmentSynchroniser $synchroniser,
        MobileSyncSettings $settings,
    ): int {
        if (! $settings->isConfigured()) {
            $this->components->warn('La synchronisation mobile n’est pas configurée sur ce poste.');

            return self::FAILURE;
        }

        $cabinetIds = $this->cabinetIds();

        if ($cabinetIds === []) {
            $this->components->warn('Aucun cabinet à synchroniser.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($cabinetIds as $cabinetId) {
            $report = $synchroniser->synchronise($cabinetId);

            if ($report->failed()) {
                $failed = true;
                // Being offline is the expected state for a local-first
                // installation, not a fault worth an error-level message.
                $report->offline
                    ? $this->components->info(sprintf('Cabinet %d : %s', $cabinetId, $report->error))
                    : $this->components->error(sprintf('Cabinet %d : %s', $cabinetId, $report->error));

                continue;
            }

            $this->components->info(sprintf(
                'Cabinet %d : %d reçus (%d créés, %d mis à jour, %d supprimés, %d ignorés), %d envoyés.',
                $cabinetId,
                $report->pulled,
                $report->created,
                $report->updated,
                $report->deleted,
                $report->skipped,
                $report->pushed,
            ));

            if ($report->conflicts > 0) {
                $this->components->warn(sprintf(
                    'Cabinet %d : %d rendez-vous modifié(s) des deux côtés ; la version locale est conservée.',
                    $cabinetId,
                    $report->conflicts,
                ));
            }

            if ($report->rejections !== []) {
                $this->components->warn(sprintf(
                    'Cabinet %d : %d évènement(s) refusé(s) — %s',
                    $cabinetId,
                    count($report->rejections),
                    implode(', ', array_unique($report->rejections)),
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function cabinetIds(): array
    {
        $requested = $this->option('cabinet');

        if ($requested !== null) {
            return [(int) $requested];
        }

        return array_values(Cabinet::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }
}
