<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cabinets are now listed in the patient app from the moment they are
 * provisioned. Give every cabinet that never had a directory row one, listed,
 * so existing doctors appear as soon as they are active. A row that already
 * exists records a deliberate choice and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('cabinets')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('cabinet_public_profiles')
                ->whereColumn('cabinet_public_profiles.cabinet_id', 'cabinets.id'))
            ->select('id')
            // By id, not offset: each insert shrinks the NOT EXISTS set.
            ->chunkById(500, function ($cabinets) use ($now): void {
                DB::table('cabinet_public_profiles')->insert($cabinets->map(fn ($cabinet): array => [
                    'cabinet_id' => $cabinet->id,
                    'is_listed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        // Irreversible by design: an inserted row is indistinguishable from
        // one an admin has since edited.
    }
};
