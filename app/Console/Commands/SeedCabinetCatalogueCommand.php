<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Services\Cabinet\CabinetCatalogueProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the per-cabinet reference catalogue.
 *
 * New cabinets get this from CabinetProvisioningService. Cabinets created
 * before that existed hold nothing, because the install seeders put the whole
 * catalogue on whichever cabinet was in context at the time.
 */
class SeedCabinetCatalogueCommand extends Command
{
    protected $signature = 'cabinets:seed-catalogue
        {--cabinet= : Restrict to one cabinet id}
        {--dry-run : Report what each cabinet is missing without writing}';

    protected $description = 'Give every cabinet the default exams, medications and examination groupings';

    public function handle(CabinetCatalogueProvisioner $provisioner): int
    {
        $cabinets = Cabinet::query()
            ->when(
                $this->option('cabinet') !== null,
                fn ($query) => $query->whereKey((int) $this->option('cabinet')),
            )
            ->orderBy('id')
            ->get();

        if ($cabinets->isEmpty()) {
            $this->warn('No cabinet matched.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($cabinets as $cabinet) {
            $before = $this->counts($cabinet);

            if ($dryRun) {
                $rows[] = [$cabinet->getKey(), $cabinet->name, $before['exams'], $before['medications'], $before['bilan_types'], '—'];

                continue;
            }

            $added = $provisioner->provisionFor($cabinet);

            $rows[] = [
                $cabinet->getKey(),
                $cabinet->name,
                $before['exams'],
                $before['medications'],
                $before['bilan_types'],
                sprintf('+%d exams, +%d meds, +%d groupings', $added['exams'], $added['medications'], $added['bilan_types']),
            ];
        }

        $this->table(
            ['Cabinet', 'Name', 'Exams', 'Medications', 'Groupings', $dryRun ? 'Dry run' : 'Added'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * @return array{exams: int, medications: int, bilan_types: int}
     */
    private function counts(Cabinet $cabinet): array
    {
        $id = (int) $cabinet->getKey();
        $count = static fn (string $table): int => (int) DB::table($table)->where('cabinet_id', $id)->count();

        return [
            'exams' => $count('exams'),
            'medications' => $count('medications'),
            'bilan_types' => $count('bilan_types'),
        ];
    }
}
