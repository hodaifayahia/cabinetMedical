<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merged duplicate is archived, not erased, and points at the dossier it
     * was merged into. Sync follows the pointer: an appointment published by
     * another installation under the duplicate's `public_id` lands on the
     * surviving dossier instead of bringing the duplicate back.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->foreignId('merged_into_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
