<?php

namespace App\Services\Cabinet;

use App\Models\Cabinet;
use Illuminate\Support\Facades\DB;

/**
 * Gives a cabinet the reference catalogue every cabinet is expected to open with.
 *
 * `exams`, `medications` and `bilan_types` all carry `cabinet_id`, but the
 * seeders that populate them run once at install and land everything on
 * whichever cabinet happened to be in context. Cabinets created afterwards
 * through {@see CabinetProvisioningService} therefore started with an empty
 * prescription list and an empty examination catalogue, and the doctor had no
 * way to fill either from inside the application.
 *
 * Writes go through the query builder rather than the models on purpose.
 * BelongsToCabinet's `creating` hook rewrites `cabinet_id` to the authenticated
 * user's own cabinet, so provisioning cabinet B while signed in as a member of
 * cabinet A would silently file the rows under A. The query builder also skips
 * the global scope, which would otherwise hide the existing rows this method
 * checks against.
 *
 * Idempotent: a name already present for the cabinet is left alone, so it is
 * safe to re-run over cabinets that are already partly populated.
 */
class CabinetCatalogueProvisioner
{
    /**
     * The examination groupings exam categories resolve through. Kept in step
     * with ConfigurationSeeder, which seeds the same three for the install
     * cabinet.
     *
     * @var list<array{name: string, description: string, category: string}>
     */
    private const BILAN_TYPES = [
        ['name' => 'Labo', 'description' => 'Analyses biologiques', 'category' => 'labo'],
        ['name' => 'Cardio', 'description' => 'Explorations cardiaques', 'category' => 'cardio'],
        ['name' => 'Radio', 'description' => 'Imagerie et explorations', 'category' => 'radio'],
        ['name' => 'Bilan lipidique', 'description' => 'Bilan biologique bilan lipidique', 'category' => 'labo'],
        ['name' => 'Bilan rénal', 'description' => 'Bilan biologique bilan rénal', 'category' => 'labo'],
    ];

    /**
     * The cardiology and imaging examinations the shipped dataset has none of —
     * every one of its 72 rows is categorised "Biologie". Without these a
     * cabinet can only ever order lab work. Same list ConfigurationSeeder gives
     * the install cabinet.
     *
     * @var list<array{name: string, category: string}>
     */
    private const CORE_EXAMS = [
        ['name' => 'Glycémie à jeun', 'category' => 'labo'],
        ['name' => 'ECG', 'category' => 'cardio'],
        ['name' => 'Épreuve d’effort', 'category' => 'cardio'],
        ['name' => 'Holter ECG', 'category' => 'cardio'],
        ['name' => 'Échocardiographie', 'category' => 'cardio'],
        ['name' => 'IRM', 'category' => 'radio'],
        ['name' => 'EEG', 'category' => 'radio'],
        ['name' => 'Scanner', 'category' => 'radio'],
        ['name' => 'Radiographie thorax', 'category' => 'radio'],
        ['name' => 'Échographie abdominale', 'category' => 'radio'],
    ];

    /**
     * @return array{bilan_types: int, exams: int, medications: int}
     */
    public function provisionFor(Cabinet $cabinet): array
    {
        $cabinetId = (int) $cabinet->getKey();

        return DB::transaction(fn (): array => [
            'bilan_types' => $this->seedBilanTypes($cabinetId),
            'exams' => $this->seedExams($cabinetId),
            'medications' => $this->seedMedications($cabinetId),
        ]);
    }

    private function seedBilanTypes(int $cabinetId): int
    {
        $existing = $this->existingNames('bilan_types', $cabinetId);
        $now = now();
        $rows = [];

        foreach (self::BILAN_TYPES as $type) {
            if (isset($existing[$type['name']])) {
                continue;
            }

            $rows[] = [
                ...$type,
                'is_active' => true,
                'cabinet_id' => $cabinetId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $this->insert('bilan_types', $rows);
    }

    private function seedExams(int $cabinetId): int
    {
        /** @var list<array{name?: string, category?: string}> $dataset */
        $dataset = [...self::CORE_EXAMS, ...$this->dataset('exams.json')];

        if ($dataset === []) {
            return 0;
        }

        // Resolve the cabinet's own grouping names: an exam category points at
        // a bilan_type belonging to the same cabinet, never a shared one.
        // First per category, not last: several groupings share the "labo"
        // category, and pluck() keyed by category would leave every lab exam
        // filed under whichever happened to be created last.
        $groupings = DB::table('bilan_types')
            ->where('cabinet_id', $cabinetId)
            ->orderBy('id')
            ->get(['name', 'category'])
            ->groupBy('category')
            ->map(static fn ($rows): string => (string) $rows->first()->name);

        $existing = $this->existingNames('exams', $cabinetId);
        $now = now();
        $rows = [];

        foreach ($dataset as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '' || isset($existing[$name]) || isset($rows[$name])) {
                continue;
            }

            $category = $this->normaliseCategory($row['category'] ?? null);

            $rows[$name] = [
                'name' => $name,
                'category' => $groupings[$category] ?? $category,
                'is_active' => true,
                'cabinet_id' => $cabinetId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $this->insert('exams', array_values($rows));
    }

    private function seedMedications(int $cabinetId): int
    {
        /** @var list<array{name?: string, dci?: string, form?: string, dosage?: string, notes?: string}> $dataset */
        $dataset = $this->dataset('medications.json');

        if ($dataset === []) {
            return 0;
        }

        $existing = $this->existingNames('medications', $cabinetId);
        $now = now();
        $rows = [];

        foreach ($dataset as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '' || isset($existing[$name]) || isset($rows[$name])) {
                continue;
            }

            $rows[$name] = [
                'name' => $name,
                'dci' => $row['dci'] ?? null,
                'form' => $row['form'] ?? null,
                'dosage' => $row['dosage'] ?? null,
                'notes' => $row['notes'] ?? null,
                'is_active' => true,
                'cabinet_id' => $cabinetId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $this->insert('medications', array_values($rows));
    }

    /**
     * Names already held by this cabinet, keyed for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function existingNames(string $table, int $cabinetId): array
    {
        return DB::table($table)
            ->where('cabinet_id', $cabinetId)
            ->pluck('name')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        // Chunked: SQLite caps how many bound parameters one statement takes,
        // and the medication catalogue is far past it.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dataset(string $file): array
    {
        $path = database_path('data/'.$file);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mirrors ExamSeeder: the shipped dataset labels categories in prose
     * ("Biologie", "Imagerie"), which has to collapse onto the three groupings.
     */
    private function normaliseCategory(mixed $category): string
    {
        $normalised = mb_strtolower(trim((string) $category));

        return match (true) {
            str_contains($normalised, 'cardio') => 'cardio',
            str_contains($normalised, 'radio'),
            str_contains($normalised, 'imagerie'),
            str_contains($normalised, 'echo'),
            str_contains($normalised, 'irm'),
            str_contains($normalised, 'scanner') => 'radio',
            default => 'labo',
        };
    }
}
