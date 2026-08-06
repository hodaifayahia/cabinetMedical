<?php

namespace App\Backups;

use PDO;
use Throwable;

final class StagedSqliteValidator
{
    /** @param list<string> $requiredTables */
    public function __construct(
        private readonly ?string $migrationDirectory = null,
        private readonly array $requiredTables = [
            'migrations',
            'users',
            'patients',
            'application_settings',
        ],
    ) {}

    /**
     * Only exact-current migration sets are accepted in this increment. This
     * deliberately prevents arbitrary project migrations from running while
     * a restored database is outside the supervised offline process.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function validate(string $databasePath, array $manifest): array
    {
        $resolved = realpath($databasePath);

        if (! is_string($resolved) || ! is_file($resolved) || is_link($databasePath) || ! is_readable($resolved)) {
            throw new BackupArchiveException('The staged restore database is unavailable.');
        }

        $size = filesize($resolved);

        if (! is_int($size) || $size < 16 || $size > MsBackupArchiveVerifier::MAXIMUM_ENTRY_BYTES) {
            throw new BackupArchiveException('The staged restore database has an invalid size.');
        }

        $handle = @fopen($resolved, 'rb');
        $header = is_resource($handle) ? fread($handle, 16) : false;

        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\0") {
            throw new BackupArchiveException('The staged restore database is not valid SQLite.');
        }

        try {
            $pdo = new PDO('sqlite:'.$resolved, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
            $pdo->exec('PRAGMA trusted_schema = OFF');
            $this->assertIntegrity($pdo);
            $this->assertRequiredTables($pdo);
            $migrations = $this->snapshotMigrations($pdo);
            $this->assertManifestMigrations($manifest, $migrations);
            $this->assertCurrentMigrationCompatibility($migrations);
            $pdo = null;
        } catch (BackupArchiveException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupArchiveException('The staged restore database could not be validated safely.');
        }

        return $migrations;
    }

    private function assertIntegrity(PDO $pdo): void
    {
        $statement = $pdo->query('PRAGMA integrity_check');

        if ($statement === false) {
            throw new BackupArchiveException('The staged restore database failed integrity validation.');
        }

        $rows = 0;
        $valid = false;

        while (($result = $statement->fetchColumn()) !== false) {
            $rows++;

            if ($rows > 1000 || ! is_string($result) || strlen($result) > 4096) {
                throw new BackupArchiveException('The staged restore database failed integrity validation.');
            }

            if ($rows === 1 && $result === 'ok') {
                $valid = true;
            } else {
                $valid = false;
            }
        }

        if (! $valid || $rows !== 1) {
            throw new BackupArchiveException('The staged restore database failed integrity validation.');
        }

        $foreignKeys = $pdo->query('PRAGMA foreign_key_check');

        if ($foreignKeys === false || $foreignKeys->fetch() !== false) {
            throw new BackupArchiveException('The staged restore database failed referential-integrity validation.');
        }
    }

    private function assertRequiredTables(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
        );

        foreach ($this->requiredTables as $table) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table) !== 1
                || ! $statement->execute([$table])
                || $statement->fetchColumn() === false) {
                throw new BackupArchiveException('The staged restore database is missing a required application table.');
            }
        }
    }

    /** @return list<string> */
    private function snapshotMigrations(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT migration FROM migrations ORDER BY migration');

        if ($statement === false) {
            throw new BackupArchiveException('The staged restore migration metadata is unavailable.');
        }

        $migrations = [];

        while (($migration = $statement->fetchColumn()) !== false) {
            if (! is_string($migration)
                || preg_match('/\A[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+\z/', $migration) !== 1
                || in_array($migration, $migrations, true)) {
                throw new BackupArchiveException('The staged restore migration metadata is malformed.');
            }

            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $migrations
     */
    private function assertManifestMigrations(array $manifest, array $migrations): void
    {
        $latest = $migrations === [] ? null : max($migrations);

        if (($manifest['schema_version'] ?? null) !== MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION
            || ($manifest['migration_count'] ?? null) !== count($migrations)
            || ($manifest['latest_migration'] ?? null) !== $latest
            || ($manifest['migration_set_sha256'] ?? null) !== hash('sha256', implode("\n", $migrations))) {
            throw new BackupArchiveException('The staged database does not match its backup migration manifest.');
        }
    }

    /** @param list<string> $snapshotMigrations */
    private function assertCurrentMigrationCompatibility(array $snapshotMigrations): void
    {
        $directory = $this->migrationDirectory ?? database_path('migrations');
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php');

        if (! is_array($files)) {
            throw new BackupArchiveException('Application migration metadata could not be inspected.');
        }

        $available = array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files,
        );
        sort($available, SORT_STRING);

        if ($snapshotMigrations !== $available) {
            throw new BackupArchiveException(
                'The backup migration set is not an exact match for this application build; offline forward migrations are not yet enabled.',
            );
        }
    }
}
