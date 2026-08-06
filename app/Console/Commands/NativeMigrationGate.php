<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class NativeMigrationGate extends Command
{
    private const PROTOCOL = 'medismart-native-migration-state';

    private const SCHEMA_VERSION = 1;

    private const REQUIRED_TABLES = [
        'application_settings',
        'appointments',
        'documents',
        'encounters',
        'failed_jobs',
        'jobs',
        'migrations',
        'patients',
        'prescriptions',
        'users',
    ];

    protected $signature = 'medismart:migration:native-state
                            {operation : Exact native operation: inspect or snapshot}';

    protected $description = 'Internal native-supervisor database migration preflight helper';

    public function __construct()
    {
        parent::__construct();
        $this->setHidden(true);
    }

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli' || getenv('MEDISMART_NATIVE_MIGRATION') !== '1') {
            return self::FAILURE;
        }

        $operation = (string) $this->argument('operation');
        if (! in_array($operation, ['inspect', 'snapshot'], true)) {
            return self::FAILURE;
        }

        try {
            $database = $this->managedDatabasePath();
            $snapshot = null;
            $checkpoint = null;

            if ($operation === 'snapshot') {
                $snapshot = $this->managedSnapshotPath();
                $checkpoint = $this->createSnapshot($database, $snapshot);
                $database = $snapshot;
            }

            $inspection = $this->inspectDatabase($database);
            $this->line($this->encode([
                'protocol' => self::PROTOCOL,
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => $operation,
                'integrity_ok' => $inspection['integrity_ok'],
                'foreign_keys_ok' => $inspection['foreign_keys_ok'],
                'journal_mode' => $inspection['journal_mode'],
                'migrations_table_present' => $inspection['migrations_table_present'],
                'expected_migrations' => $inspection['expected_migrations'],
                'applied_migrations' => $inspection['applied_migrations'],
                'pending_migrations' => $inspection['pending_migrations'],
                'required_tables_present' => $inspection['required_tables_present'],
                'missing_required_tables' => $inspection['missing_required_tables'],
                'snapshot_created' => $snapshot !== null,
                'checkpoint' => $checkpoint,
            ]));

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    private function managedDatabasePath(): string
    {
        $database = getenv('MEDISMART_MIGRATION_DATABASE');
        if (! is_string($database) || ! $this->isAbsolutePath($database)) {
            throw new RuntimeException('The native database path is invalid.');
        }

        $configured = (string) config('database.connections.sqlite.database');
        if (! hash_equals($database, $configured)) {
            throw new RuntimeException('The native and Laravel database paths differ.');
        }

        return $this->canonicalRegularFile($database);
    }

    private function managedSnapshotPath(): string
    {
        $snapshot = getenv('MEDISMART_MIGRATION_SNAPSHOT');
        $recoveryRoot = getenv('MEDISMART_MIGRATION_RECOVERY_ROOT');
        if (! is_string($snapshot) || ! is_string($recoveryRoot)
            || ! $this->isAbsolutePath($snapshot) || ! $this->isAbsolutePath($recoveryRoot)) {
            throw new RuntimeException('The native snapshot path is invalid.');
        }

        $canonicalRoot = realpath($recoveryRoot);
        $canonicalParent = realpath(dirname($snapshot));
        if ($canonicalRoot === false || $canonicalParent === false
            || is_link($recoveryRoot) || is_link(dirname($snapshot))
            || ! is_dir($canonicalRoot) || ! is_dir($canonicalParent)
            || ! hash_equals($canonicalRoot, $canonicalParent)
            || file_exists($snapshot) || is_link($snapshot)
            || ! preg_match('/^migration-safety-[0-9a-f-]{36}\.sqlite$/D', basename($snapshot))) {
            throw new RuntimeException('The native snapshot destination is invalid.');
        }

        return $canonicalParent.DIRECTORY_SEPARATOR.basename($snapshot);
    }

    /**
     * @return array{busy: int, log: int, checkpointed: int}
     */
    private function createSnapshot(string $database, string $snapshot): array
    {
        $pdo = $this->connect($database, false);
        $this->assertIntegrity($pdo);

        $checkpoint = $this->query($pdo, 'PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
        if (! is_array($checkpoint) || count($checkpoint) < 3
            || (int) $checkpoint[0] !== 0 || (int) $checkpoint[1] !== (int) $checkpoint[2]) {
            throw new RuntimeException('SQLite WAL checkpoint did not complete.');
        }

        $quotedSnapshot = $pdo->quote($snapshot);
        if (! is_string($quotedSnapshot) || $quotedSnapshot === '') {
            throw new RuntimeException('SQLite snapshot path could not be quoted.');
        }
        $pdo->exec('VACUUM INTO '.$quotedSnapshot);
        $pdo = null;

        clearstatcache(true, $snapshot);
        $this->canonicalRegularFile($snapshot);
        $handle = fopen($snapshot, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('SQLite snapshot could not be opened.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('SQLite snapshot could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }

        return [
            'busy' => (int) $checkpoint[0],
            'log' => (int) $checkpoint[1],
            'checkpointed' => (int) $checkpoint[2],
        ];
    }

    /**
     * @return array{
     *   integrity_ok: bool,
     *   foreign_keys_ok: bool,
     *   journal_mode: string,
     *   migrations_table_present: bool,
     *   expected_migrations: list<string>,
     *   applied_migrations: list<string>,
     *   pending_migrations: list<string>,
     *   required_tables_present: bool,
     *   missing_required_tables: list<string>
     * }
     */
    private function inspectDatabase(string $database): array
    {
        $pdo = $this->connect($database, true);
        $this->assertIntegrity($pdo);

        $tables = $this
            ->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (array_filter($tables, fn (mixed $table): bool => ! is_string($table)) !== []) {
            throw new RuntimeException('SQLite table inventory is invalid.');
        }
        /** @var list<string> $tables */
        $tables = array_values($tables);

        $expected = $this->expectedMigrations();
        $migrationsTablePresent = in_array('migrations', $tables, true);
        $applied = [];
        if ($migrationsTablePresent) {
            $rows = $this->query($pdo, 'SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            if (array_filter($rows, fn (mixed $row): bool => ! is_string($row)) !== []) {
                throw new RuntimeException('SQLite migration inventory is invalid.');
            }
            /** @var list<string> $applied */
            $applied = array_values($rows);
        }

        if (count(array_unique($applied)) !== count($applied)) {
            throw new RuntimeException('SQLite migration inventory contains duplicates.');
        }

        $missingRequired = array_values(array_diff(self::REQUIRED_TABLES, $tables));

        return [
            'integrity_ok' => true,
            'foreign_keys_ok' => true,
            'journal_mode' => strtolower((string) $this->query($pdo, 'PRAGMA journal_mode')->fetchColumn()),
            'migrations_table_present' => $migrationsTablePresent,
            'expected_migrations' => $expected,
            'applied_migrations' => $applied,
            'pending_migrations' => array_values(array_diff($expected, $applied)),
            'required_tables_present' => $missingRequired === [],
            'missing_required_tables' => $missingRequired,
        ];
    }

    private function connect(string $database, bool $queryOnly): PDO
    {
        $this->assertSqliteHeader($database);
        try {
            $pdo = new PDO('sqlite:'.$database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('SQLite could not be opened.', 0, $exception);
        }

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($queryOnly) {
            $pdo->exec('PRAGMA query_only = ON');
        }

        return $pdo;
    }

    private function assertIntegrity(PDO $pdo): void
    {
        $integrity = $this->query($pdo, 'PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        if ($integrity !== ['ok']) {
            throw new RuntimeException('SQLite integrity check failed.');
        }

        if ($this->query($pdo, 'PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('SQLite foreign-key check failed.');
        }
    }

    /** @return list<string> */
    private function expectedMigrations(): array
    {
        $files = glob(database_path('migrations/*.php'));
        if (! is_array($files) || $files === []) {
            throw new RuntimeException('Bundled migration inventory is unavailable.');
        }

        $migrations = array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files,
        );
        sort($migrations, SORT_STRING);
        if (count(array_unique(array_map('strtolower', $migrations))) !== count($migrations)) {
            throw new RuntimeException('Bundled migration names are ambiguous.');
        }

        return $migrations;
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (! $statement instanceof PDOStatement) {
            throw new RuntimeException('SQLite query could not be prepared.');
        }

        return $statement;
    }

    private function canonicalRegularFile(string $path): string
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Managed SQLite file is invalid.');
        }
        $canonical = realpath($path);
        if ($canonical === false || ! hash_equals(realpath(dirname($path)) ?: '', dirname($canonical))) {
            throw new RuntimeException('Managed SQLite file escaped its directory.');
        }

        return $canonical;
    }

    private function assertSqliteHeader(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('SQLite header is unavailable.');
        }
        try {
            $header = fread($handle, 16);
        } finally {
            fclose($handle);
        }
        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException('SQLite header is invalid.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return ! str_contains($path, "\0")
            && (str_starts_with($path, DIRECTORY_SEPARATOR)
                || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1);
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Native migration result could not be encoded.', 0, $exception);
        }
    }
}
