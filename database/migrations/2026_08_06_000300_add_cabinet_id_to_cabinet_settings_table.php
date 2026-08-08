<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert the settings table from an implicit singleton into a per-cabinet
     * row keyed by a unique cabinet_id.
     */
    public function up(): void
    {
        Schema::table('cabinet_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cabinet_settings', 'cabinet_id')) {
                $table->foreignId('cabinet_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('cabinets')
                    ->cascadeOnDelete();
                $table->unique('cabinet_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cabinet_settings', 'cabinet_id')) {
                $table->dropConstrainedForeignId('cabinet_id');
            }
        });
    }
};
