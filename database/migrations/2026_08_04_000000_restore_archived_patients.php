<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restore every legacy patient that was archived before archiving was removed.
     */
    public function up(): void
    {
        DB::table('patients')
            ->whereNotNull('deleted_at')
            ->update([
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Restored patients must remain available when this migration is rolled back.
     */
    public function down(): void
    {
        // Intentionally irreversible: there is no safe way to identify former archives.
    }
};
