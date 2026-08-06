<?php

namespace Database\Seeders;

use App\Models\BilanType;
use App\Models\Exam;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExamSeeder extends Seeder
{
    /**
     * Seed the examination reference catalogue from the bundled dataset.
     */
    public function run(): void
    {
        $path = database_path('data/exams.json');

        if (! is_file($path)) {
            $this->consoleCommand()?->warn("Exam dataset not found at [{$path}]; skipping.");

            return;
        }

        /** @var array<int, array<string, mixed>> $exams */
        $exams = json_decode((string) file_get_contents($path), true) ?: [];

        $imported = 0;

        DB::transaction(function () use ($exams, &$imported): void {
            foreach ($exams as $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                Exam::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'category' => $this->categoryName($row['category'] ?? null),
                        'is_active' => true,
                    ],
                );

                $imported++;
            }
        });

        $this->consoleCommand()?->info("{$imported} exams imported.");
    }

    private function normalizeCategory(mixed $category): string
    {
        $normalized = mb_strtolower(trim((string) $category));

        return match (true) {
            str_contains($normalized, 'cardio') => 'cardio',
            str_contains($normalized, 'radio'),
            str_contains($normalized, 'imagerie'),
            str_contains($normalized, 'echo'),
            str_contains($normalized, 'irm'),
            str_contains($normalized, 'scanner') => 'radio',
            default => 'labo',
        };
    }

    private function categoryName(mixed $category): string
    {
        $normalized = $this->normalizeCategory($category);

        return (string) (BilanType::query()
            ->where('category', $normalized)
            ->orderBy('id')
            ->value('name') ?? $normalized);
    }

    private function consoleCommand(): ?Command
    {
        /** @var mixed $command */
        $command = $this->command;

        return $command instanceof Command ? $command : null;
    }
}
