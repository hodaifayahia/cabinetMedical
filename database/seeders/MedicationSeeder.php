<?php

namespace Database\Seeders;

use App\Models\Medication;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MedicationSeeder extends Seeder
{
    /**
     * Seed the medication reference catalogue from the bundled dataset.
     */
    public function run(): void
    {
        $path = database_path('data/medications.json');

        if (! is_file($path)) {
            $this->consoleCommand()?->warn("Medication dataset not found at [{$path}]; skipping.");

            return;
        }

        /** @var array<int, array<string, mixed>> $medications */
        $medications = json_decode((string) file_get_contents($path), true) ?: [];

        $imported = 0;

        DB::transaction(function () use ($medications, &$imported): void {
            foreach ($medications as $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                Medication::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'dci' => $row['dci'] ?? null,
                        'form' => $row['form'] ?? null,
                        'dosage' => $row['dosage'] ?? null,
                        'notes' => $row['notes'] ?? null,
                        'is_active' => true,
                    ],
                );

                $imported++;
            }
        });

        $this->consoleCommand()?->info("{$imported} medications imported.");
    }

    private function consoleCommand(): ?Command
    {
        /** @var mixed $command */
        $command = $this->command;

        return $command instanceof Command ? $command : null;
    }
}
