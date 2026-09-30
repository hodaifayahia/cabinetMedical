<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Skips a test class unless the default connection is SQLite.
 *
 * Only for behaviour that exists solely on the desktop, where the database is
 * an SQLite file: .msbackup archives snapshot that file with VACUUM INTO, and
 * offline restore validates and swaps it. None of this runs on the MariaDB
 * server, so these cases cannot run there. Never use it to hide a failure.
 *
 * Laravel's setUpTraits() calls setUpRequiresSqlite() after RefreshDatabase and
 * before the rest of the class's setUp(), so a skipped case never migrates or
 * builds its fixtures.
 */
trait RequiresSqlite
{
    protected function setUpRequiresSqlite(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            $this->markTestSkipped(
                "Desktop-only behaviour built on the SQLite database file; the default connection is {$driver}.",
            );
        }
    }
}
