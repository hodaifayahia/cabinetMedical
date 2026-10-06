<?php

namespace App\CabinetTransfer;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The PC's side of a transfer: copies one cabinet from the online service
 * into this empty installation, rows with their original ids and files
 * checked by SHA-256, then verifies every count.
 *
 * Works in steps bounded by a deadline so a background job never runs past
 * the worker's limit; each step resumes from TransferState and can be run
 * again safely (rows are upserted by id, files rewritten).
 */
final class OnlineCabinetImporter
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Advance the transfer until it is copied or the deadline passes.
     *
     * @return bool true when every row and file is in place and verified
     */
    public function step(TransferState $state, float $deadline): bool
    {
        if ($state->get('phase') === 'manifest') {
            $this->readManifest($state);
        }

        if ($state->get('phase') === 'files' && ! $this->copyFiles($state, $deadline)) {
            return false;
        }

        if ($state->get('phase') === 'rows' && ! $this->copyRows($state, $deadline)) {
            return false;
        }

        if ($state->get('phase') === 'verify') {
            $this->verify($state);
        }

        return $state->get('phase') === 'copied';
    }

    /**
     * Back to an empty installation after a failed transfer: removes every
     * copied row and staged file, so the doctor can start again.
     */
    public function reset(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (array_reverse(CabinetTransferCatalog::allTables()) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });

        File::deleteDirectory(TransferState::stagingDirectory());
    }

    private function readManifest(TransferState $state): void
    {
        if (Cabinet::query()->exists() || User::query()->exists()) {
            throw new RuntimeException('Ce poste contient déjà un cabinet : le transfert ne peut se faire que sur une installation neuve.');
        }

        $manifest = $this->get($state, '/api/v1/cabinet-transfer/manifest')->json();

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== CabinetTransferCatalog::FORMAT) {
            throw new RuntimeException('Le service en ligne a répondu dans un format inconnu. Mettez Drclick à jour sur ce poste.');
        }

        $remoteSchema = (string) ($manifest['schema'] ?? '');
        $localSchema = (string) DB::table('migrations')->max('migration');

        if ($remoteSchema !== '' && strcmp($remoteSchema, $localSchema) > 0) {
            throw new RuntimeException('Le service en ligne utilise une version plus récente de Drclick. Mettez Drclick à jour sur ce poste, puis relancez le transfert.');
        }

        File::deleteDirectory(TransferState::stagingDirectory());

        $state->update([
            'manifest' => $manifest,
            'phase' => 'files',
            'message' => 'Copie des documents…',
        ]);
    }

    private function copyFiles(TransferState $state, float $deadline): bool
    {
        /** @var list<array{key: string, size: int, sha256: string}> $files */
        $files = $state->get('manifest')['files'] ?? [];
        $index = (int) $state->get('file_index', 0);

        while ($index < count($files)) {
            if (microtime(true) >= $deadline) {
                $state->update(['file_index' => $index]);

                return false;
            }

            $file = $files[$index];
            $target = $this->stagedPath($file['key']);
            File::ensureDirectoryExists(dirname($target));

            try {
                $response = $this->client($state)
                    ->timeout(300)
                    ->get('/api/v1/cabinet-transfer/files/'.$file['key']);
            } catch (ConnectionException) {
                throw new RuntimeException('Le service en ligne Drclick est injoignable. Vérifiez la connexion Internet puis réessayez.');
            }

            if ($response->successful()) {
                File::put($target, $response->body());
            }

            if (! $response->successful()
                || ! is_file($target)
                || filesize($target) !== (int) $file['size']
                || ! hash_equals((string) $file['sha256'], (string) hash_file('sha256', $target))) {
                throw new RuntimeException('Un document n’a pas été reçu intact ('.$file['key'].'). Vérifiez la connexion puis réessayez.');
            }

            $index++;
            $state->update(['file_index' => $index, 'copied_files' => $index]);
        }

        $state->update(['phase' => 'rows', 'message' => 'Copie des dossiers…']);

        return true;
    }

    private function copyRows(TransferState $state, float $deadline): bool
    {
        $tables = CabinetTransferCatalog::allTables();
        $remoteTables = $state->get('manifest')['tables'] ?? [];

        return Schema::withoutForeignKeyConstraints(function () use ($state, $deadline, $tables, $remoteTables): bool {
            $tableIndex = (int) $state->get('table_index', 0);
            $after = (int) $state->get('after', 0);

            while ($tableIndex < count($tables)) {
                $table = $tables[$tableIndex];

                if (! array_key_exists($table, $remoteTables) || ! Schema::hasTable($table)) {
                    $tableIndex++;
                    $after = 0;

                    continue;
                }

                if (microtime(true) >= $deadline) {
                    $state->update(['table_index' => $tableIndex, 'after' => $after]);

                    return false;
                }

                $page = $this->get($state, "/api/v1/cabinet-transfer/tables/{$table}", ['after' => $after])->json();
                $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];

                DB::transaction(function () use ($table, $rows): void {
                    // SQLite ignores foreign_keys=OFF inside an open transaction
                    // (a caller's, or a test's): defer the checks instead.
                    // Dangling links are repaired once everything is in.
                    if (DB::getDriverName() === 'sqlite') {
                        DB::statement('PRAGMA defer_foreign_keys = ON');
                    }

                    $this->insert($table, $rows);
                });

                $next = $page['next_after'] ?? null;
                $copied = (int) $state->get('copied_rows', 0) + count($rows);

                if (is_int($next)) {
                    $after = $next;
                } else {
                    $tableIndex++;
                    $after = 0;
                }

                $state->update(['table_index' => $tableIndex, 'after' => $after, 'copied_rows' => $copied]);
            }

            $state->update(['phase' => 'verify', 'message' => 'Vérification…']);

            return true;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)) {
            $this->insertRoleLinks($table, $rows);

            return;
        }

        $columns = array_flip($this->columns($table));
        $prepared = array_map(
            static fn (array $row): array => array_intersect_key($row, $columns),
            $rows,
        );

        foreach (array_chunk($prepared, 100) as $chunk) {
            DB::table($table)->upsert($chunk, ['id']);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRoleLinks(string $table, array $rows): void
    {
        $morph = (new User)->getMorphClass();

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $guard = (string) ($row['guard_name'] ?? 'web');

            if ($table === 'model_has_roles') {
                $id = Role::findOrCreate($name, $guard)->getKey();
                $key = 'role_id';
            } else {
                $permission = Permission::query()->where(['name' => $name, 'guard_name' => $guard])->first();

                if ($permission === null) {
                    continue;
                }

                $id = $permission->getKey();
                $key = 'permission_id';
            }

            DB::table($table)->insertOrIgnore([
                $key => $id,
                'model_type' => $morph,
                'model_id' => (int) $row['model_id'],
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function verify(TransferState $state): void
    {
        $this->repairDanglingReferences();

        $manifest = $state->get('manifest');
        $summary = [];

        foreach ($manifest['tables'] ?? [] as $table => $expected) {
            if ($table === 'model_has_permissions' || ! Schema::hasTable($table)) {
                continue;
            }

            $actual = DB::table($table)->count();

            if ($actual !== (int) $expected) {
                throw new RuntimeException("Vérification refusée : {$table} contient {$actual} ligne(s) sur ce poste au lieu de {$expected}.");
            }

            $summary[$table] = $actual;
        }

        $this->placeFiles($manifest['files'] ?? []);

        $state->update([
            'phase' => 'copied',
            'message' => 'Dossiers copiés et vérifiés.',
            'summary' => [
                'patients' => $summary['patients'] ?? 0,
                'appointments' => $summary['appointments'] ?? 0,
                'consultations' => $summary['consultations'] ?? 0,
                'documents' => ($summary['documents'] ?? 0) + ($summary['uploaded_documents'] ?? 0),
                'payments' => $summary['payments'] ?? 0,
                'users' => $summary['users'] ?? 0,
            ],
        ]);
    }

    /**
     * Rows point to things the online service keeps for itself (the patient's
     * mobile account, a platform administrator who wrote a log line). Those
     * links are cleared when the column allows it; otherwise the transfer
     * stops rather than keep a broken record.
     */
    private function repairDanglingReferences(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (DB::select('PRAGMA foreign_key_check') as $violation) {
            $violation = (array) $violation;
            $table = (string) $violation['table'];
            $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");
            $column = null;

            foreach ($foreignKeys as $foreignKey) {
                $foreignKey = (array) $foreignKey;

                if ((int) $foreignKey['id'] === (int) $violation['fkid']) {
                    $column = (string) $foreignKey['from'];
                }
            }

            $nullable = collect(DB::select("PRAGMA table_info('{$table}')"))
                ->first(static fn (object $info): bool => $info->name === $column);

            if ($column === null || $nullable === null || (int) $nullable->notnull === 1) {
                throw new RuntimeException("Vérification refusée : une ligne de {$table} pointe vers un élément absent ({$violation['parent']}).");
            }

            DB::table($table)->where('rowid', $violation['rowid'])->update([$column => null]);
        }
    }

    /** @param list<array{key: string, table: string, id: int, column: string}> $files */
    private function placeFiles(array $files): void
    {
        foreach ($files as $file) {
            $definition = collect(CabinetTransferCatalog::FILES)->first(
                static fn (array $entry): bool => $entry['table'] === $file['table'] && $entry['column'] === $file['column'],
            );
            $row = (array) DB::table($file['table'])->where('id', $file['id'])->first();
            $relative = $row[$file['column']] ?? null;
            $disk = $definition['disk'] ?? ($row['disk'] ?? null);

            if (! is_string($relative) || $relative === '' || str_contains($relative, '..')
                || ! in_array($disk, ['local', 'public'], true)) {
                throw new RuntimeException('Un document a un emplacement invalide ('.$file['key'].').');
            }

            $storage = Storage::disk($disk);
            File::ensureDirectoryExists(dirname($storage->path($relative)));
            File::copy($this->stagedPath($file['key']), $storage->path($relative));
        }

        File::deleteDirectory(TransferState::stagingDirectory());
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }

    private function stagedPath(string $key): string
    {
        return TransferState::stagingDirectory().'/'.str_replace(':', '_', $key);
    }

    /** @param array<string, mixed> $query */
    private function get(TransferState $state, string $path, array $query = []): Response
    {
        try {
            $response = $this->client($state)->get($path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('Le service en ligne Drclick est injoignable. Vérifiez la connexion Internet puis réessayez.');
        }

        if ($response->status() === 403) {
            throw new RuntimeException('Seul le médecin titulaire du cabinet peut transférer ses dossiers.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Le service en ligne a refusé la demande (code '.$response->status().'). Réessayez plus tard.');
        }

        return $response;
    }

    private function client(TransferState $state): PendingRequest
    {
        return $this->http
            ->baseUrl((string) $state->get('endpoint'))
            ->withToken($state->token())
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(120)
            ->withoutRedirecting();
    }
}
