<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the database connection as shell assignments for
 * `scripts/server/nightly-backup.sh`.
 *
 * The hosting disables proc_open/exec for PHP, so the dump itself runs in the
 * cron's shell with sqlite3 / mariadb-dump. This lets that script read the
 * same connection Laravel uses, .env interpolation included, without parsing
 * .env by hand. The script `eval`s the output; nothing is written to disk.
 */
class ServerBackupShellEnv extends Command
{
    protected $signature = 'drclick:server-backup:env';

    protected $description = 'Print the database connection for the nightly server backup script';

    public function handle(): int
    {
        $name = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = (array) config("database.connections.{$name}", []);
        $driver = (string) ($connection['driver'] ?? '');

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            $this->components->error("Le pilote de base de données [{$driver}] n’est pas pris en charge par la sauvegarde serveur.");

            return self::FAILURE;
        }

        $values = ['DRCLICK_DB_DRIVER' => $driver];

        if ($driver === 'sqlite') {
            $values['DRCLICK_DB_DATABASE'] = (string) ($connection['database'] ?? '');
        } else {
            $values += [
                'DRCLICK_DB_DATABASE' => (string) ($connection['database'] ?? ''),
                'DRCLICK_DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
                'DRCLICK_DB_PORT' => (string) ($connection['port'] ?? '3306'),
                'DRCLICK_DB_USERNAME' => (string) ($connection['username'] ?? ''),
                'DRCLICK_DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            ];
        }

        // Raw: the console formatter would read `<info>`, `</>` or `\<` inside
        // a password as markup and print it changed, or throw on `<fg=…>`.
        foreach ($values as $key => $value) {
            $this->output->writeln($key.'='.escapeshellarg($value), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
