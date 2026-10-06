<?php

namespace App\CabinetTransfer;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The online service's side of a transfer: what one cabinet has here, page
 * by page, plus the files its rows point to. Reads only; nothing is removed
 * until the PC reports a verified copy (CabinetTransferPurger).
 */
final class CabinetTransferExporter
{
    /**
     * @return array{
     *     format: string,
     *     schema: string|null,
     *     cabinet_id: int,
     *     tables: array<string, int>,
     *     files: list<array{key: string, table: string, id: int, column: string, size: int, sha256: string}>,
     *     missing_files: int,
     *     fingerprint: string
     * }
     */
    public function manifest(Cabinet $cabinet): array
    {
        $tables = [];

        foreach (CabinetTransferCatalog::allTables() as $table) {
            if ($this->exists($table)) {
                $tables[$table] = $this->query($cabinet, $table)->count();
            }
        }

        [$files, $missing] = $this->files($cabinet);

        return [
            'format' => CabinetTransferCatalog::FORMAT,
            'schema' => $this->schemaVersion(),
            'cabinet_id' => (int) $cabinet->getKey(),
            'tables' => $tables,
            'files' => $files,
            'missing_files' => $missing,
            'fingerprint' => $this->fingerprint($cabinet),
        ];
    }

    /**
     * One page of a table, ordered by id.
     *
     * @return array{rows: list<array<string, mixed>>, next_after: int|null}
     */
    public function page(Cabinet $cabinet, string $table, int $after, int $limit = CabinetTransferCatalog::PAGE_SIZE): array
    {
        if (! in_array($table, CabinetTransferCatalog::allTables(), true) || ! $this->exists($table)) {
            throw new InvalidArgumentException('Unknown transfer table.');
        }

        // Role links have no id of their own and are few: one page.
        if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)) {
            return ['rows' => $this->roleLinks($cabinet, $table), 'next_after' => null];
        }

        $limit = max(1, min($limit, CabinetTransferCatalog::PAGE_SIZE));
        $rows = $this->query($cabinet, $table)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->blank($table, (array) $row))
            ->all();

        $last = $rows === [] ? null : (int) end($rows)['id'];

        return [
            'rows' => array_values($rows),
            'next_after' => count($rows) === $limit ? $last : null,
        ];
    }

    /**
     * Absolute path of one listed file, or null when it is not this
     * cabinet's or no longer on disk.
     */
    public function filePath(Cabinet $cabinet, string $key): ?string
    {
        if (! preg_match('/^([a-z_]+):(\d+):([a-z_]+)$/', $key, $match)) {
            return null;
        }

        [, $table, $id, $column] = $match;

        foreach (CabinetTransferCatalog::FILES as $file) {
            if ($file['table'] !== $table || $file['column'] !== $column || ! $this->exists($table)) {
                continue;
            }

            $row = DB::table($table)
                ->where('cabinet_id', $cabinet->getKey())
                ->where('id', (int) $id)
                ->first();

            if ($row === null) {
                return null;
            }

            return $this->resolve($file, (array) $row);
        }

        return null;
    }

    /**
     * Changes to any transferred row change this value: the online copy is
     * only deleted if it is still exactly what the PC received.
     */
    public function fingerprint(Cabinet $cabinet): string
    {
        $state = [];

        foreach (CabinetTransferCatalog::allTables() as $table) {
            if (! $this->exists($table)) {
                continue;
            }

            $query = $this->query($cabinet, $table);
            $state[$table] = [
                'count' => (clone $query)->count(),
                'max_id' => Schema::hasColumn($table, 'id') ? (clone $query)->max('id') : null,
                'updated' => Schema::hasColumn($table, 'updated_at') ? (string) (clone $query)->max('updated_at') : null,
            ];
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function query(Cabinet $cabinet, string $table): Builder
    {
        return match ($table) {
            'cabinets' => DB::table('cabinets')->where('id', $cabinet->getKey()),
            'model_has_roles', 'model_has_permissions' => DB::table($table)
                ->where('model_type', (new User)->getMorphClass())
                ->whereIn('model_id', DB::table('users')->where('cabinet_id', $cabinet->getKey())->select('id')),
            default => DB::table($table)->where('cabinet_id', $cabinet->getKey()),
        };
    }

    /**
     * Role and permission ids differ between installations: links travel
     * with the name, and the PC finds its own id for it.
     *
     * @return list<array{model_id: int, name: string, guard_name: string}>
     */
    private function roleLinks(Cabinet $cabinet, string $table): array
    {
        [$target, $key] = $table === 'model_has_roles'
            ? ['roles', 'role_id']
            : ['permissions', 'permission_id'];

        return $this->query($cabinet, $table)
            ->join($target, "{$target}.id", '=', "{$table}.{$key}")
            ->orderBy("{$table}.model_id")
            ->get(["{$table}.model_id", "{$target}.name", "{$target}.guard_name"])
            ->map(static fn (object $row): array => [
                'model_id' => (int) $row->model_id,
                'name' => (string) $row->name,
                'guard_name' => (string) $row->guard_name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function blank(string $table, array $row): array
    {
        foreach (CabinetTransferCatalog::BLANKED_ON_EXPORT[$table] ?? [] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /**
     * @return array{0: list<array{key: string, table: string, id: int, column: string, size: int, sha256: string}>, 1: int}
     */
    private function files(Cabinet $cabinet): array
    {
        $files = [];
        $missing = 0;

        foreach (CabinetTransferCatalog::FILES as $file) {
            if (! $this->exists($file['table']) || ! Schema::hasColumn($file['table'], $file['column'])) {
                continue;
            }

            $rows = DB::table($file['table'])
                ->where('cabinet_id', $cabinet->getKey())
                ->whereNotNull($file['column'])
                ->where($file['column'], '!=', '')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $row = (array) $row;
                $path = $this->resolve($file, $row);

                if ($path === null) {
                    $missing++;

                    continue;
                }

                $files[] = [
                    'key' => "{$file['table']}:{$row['id']}:{$file['column']}",
                    'table' => $file['table'],
                    'id' => (int) $row['id'],
                    'column' => $file['column'],
                    'size' => (int) filesize($path),
                    'sha256' => (string) hash_file('sha256', $path),
                ];
            }
        }

        return [$files, $missing];
    }

    /**
     * @param  array{table: string, column: string, disk: string|null}  $file
     * @param  array<string, mixed>  $row
     */
    private function resolve(array $file, array $row): ?string
    {
        $relative = $row[$file['column']] ?? null;
        $disk = $file['disk'] ?? (is_string($row['disk'] ?? null) ? $row['disk'] : null);

        if (! is_string($relative) || $relative === '' || ! in_array($disk, ['local', 'public'], true)) {
            return null;
        }

        if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            return null;
        }

        $storage = Storage::disk($disk);

        return $storage->exists($relative) ? $storage->path($relative) : null;
    }

    private function exists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function schemaVersion(): ?string
    {
        $latest = DB::table('migrations')->max('migration');

        return is_string($latest) ? $latest : null;
    }
}
