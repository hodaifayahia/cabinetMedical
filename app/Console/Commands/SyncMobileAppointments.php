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
        {--cabinet= : Restrict the run to one cabinet id}
        {--scheduled : Unattended run: an unlinked or offline poste is not a failure}';

    protected $description = 'Exchange appointments with the online service used by the mobile application';

    public function handle(
        MobileAppointmentSynchroniser $synchroniser,
        MobileSyncSettings $settings,
    ): int {
        $scheduled = (bool) $this->option('scheduled');

        if (! $settings->isConfigured()) {
            // The scheduler runs this every few minutes on every install,
            // the online service included; nothing to do is not a failure.
            if ($scheduled) {
                return self::SUCCESS;
            }

            $this->components->warn('La synchronisation mobile n’est pas configurée sur ce poste.');

            return self::FAILURE;
        }

        $cabinetIds = $this->cabinetIds($settings);

        if ($cabinetIds === []) {
            $this->components->warn('Aucun cabinet à synchroniser.');

            return $scheduled ? self::SUCCESS : self::FAILURE;
        }

        $failed = false;

        foreach ($cabinetIds as $cabinetId) {
            $report = $synchroniser->synchronise($cabinetId);

            if ($report->failed()) {
                // Offline is the normal state of a local-first poste: the
                // next scheduled run simply tries again. The outcome is kept
                // on the sync state for Configuration › Service en ligne.
                $failed = $failed || ! ($scheduled && $report->offline);
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
     * A link made for one cabinet syncs that cabinet only; the synchroniser
     * refuses any other one named with --cabinet.
     *
     * @return list<int>
     */
    private function cabinetIds(MobileSyncSettings $settings): array
    {
        $requested = $this->option('cabinet');

        if ($requested !== null) {
            return [(int) $requested];
        }

        $linked = $settings->cabinetId();

        if ($linked !== null) {
            return Cabinet::query()->whereKey($linked)->exists() ? [$linked] : [];
        }

        return array_values(Cabinet::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }
}
