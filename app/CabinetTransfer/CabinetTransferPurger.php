<?php

namespace App\CabinetTransfer;

use App\Models\AuditLog;
use App\Models\Cabinet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a cabinet's medical records from the online service once its PC
 * holds a verified copy. Patients keep only identity and contact, and
 * appointments stay, because the mobile app books through this service.
 */
final class CabinetTransferPurger
{
    public function __construct(private readonly CabinetTransferExporter $exporter) {}

    /**
     * @return array<string, int> rows removed per table
     *
     * @throws CabinetTransferChanged when the records changed since the PC copied them
     */
    public function purge(Cabinet $cabinet, string $fingerprint, string $installation): array
    {
        $cabinetId = (int) $cabinet->getKey();
        $files = [];

        $removed = DB::transaction(function () use ($cabinet, $cabinetId, $fingerprint, $installation, &$files): array {
            $locked = Cabinet::query()->whereKey($cabinetId)->lockForUpdate()->firstOrFail();

            if (! hash_equals($this->exporter->fingerprint($locked), $fingerprint)) {
                throw new CabinetTransferChanged;
            }

            $files = $this->filesToDelete($cabinetId);
            $removed = [];

            foreach (CabinetTransferCatalog::PURGED_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $removed[$table] = DB::table($table)->where('cabinet_id', $cabinetId)->delete();
                }
            }

            $blanked = array_values(array_filter(
                CabinetTransferCatalog::PATIENT_COLUMNS_BLANKED,
                static fn (string $column): bool => Schema::hasColumn('patients', $column),
            ));

            if ($blanked !== []) {
                DB::table('patients')
                    ->where('cabinet_id', $cabinetId)
                    ->update(array_fill_keys($blanked, null));
            }

            $locked->forceFill([
                'clinical_data_transferred_at' => now(),
                'clinical_data_transferred_to' => mb_substr($installation, 0, 120),
            ])->save();

            AuditLog::record('cabinet.records_transferred_to_desktop', $locked, [
                'installation' => mb_substr($installation, 0, 120),
                'removed' => $removed,
            ], $cabinet->owner_user_id);

            return $removed;
        });

        // After the commit: a failed transaction must not lose the files.
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return $removed;
    }

    /** @return list<array{0: string, 1: string}> */
    private function filesToDelete(int $cabinetId): array
    {
        $files = [];

        foreach (CabinetTransferCatalog::FILES as $file) {
            // Logos stay: they belong to the cabinet's settings, still online.
            if (! in_array($file['table'], CabinetTransferCatalog::PURGED_TABLES, true)
                || ! Schema::hasTable($file['table'])) {
                continue;
            }

            $rows = DB::table($file['table'])
                ->where('cabinet_id', $cabinetId)
                ->whereNotNull($file['column'])
                ->get();

            foreach ($rows as $row) {
                $row = (array) $row;
                $disk = $file['disk'] ?? ($row['disk'] ?? null);
                $path = $row[$file['column']] ?? null;

                if (in_array($disk, ['local', 'public'], true)
                    && is_string($path) && $path !== '' && ! str_contains($path, '..')) {
                    $files[] = [$disk, $path];
                }
            }
        }

        return $files;
    }
}
