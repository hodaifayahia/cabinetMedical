<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bilan_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        // Keep existing category text useful as the new description until an
        // administrator updates it from the configuration screen.
        DB::table('bilan_types')
            ->whereNull('description')
            ->whereNotNull('category')
            ->update(['description' => DB::raw('category')]);

        // Older installations stored the legacy category key on exams. Map
        // it to the corresponding Bilan category name where possible.
        $legacyCategories = DB::table('bilan_types')
            ->whereNotNull('category')
            ->orderBy('id')
            ->get(['name', 'category'])
            ->groupBy('category')
            ->map(fn ($rows) => $rows->first()->name);

        foreach ($legacyCategories as $legacy => $name) {
            DB::table('exams')
                ->where('category', $legacy)
                ->update(['category' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('bilan_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
