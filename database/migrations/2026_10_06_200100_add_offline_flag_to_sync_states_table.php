<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointment sync now runs on its own every few minutes. Being offline
     * is the normal state of a local-first poste, so Configuration › Service
     * en ligne must tell "no Internet, retrying automatically" apart from a
     * real failure that needs the doctor's attention.
     */
    public function up(): void
    {
        Schema::table('sync_states', function (Blueprint $table): void {
            $table->boolean('last_failure_offline')->default(false)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('sync_states', function (Blueprint $table): void {
            $table->dropColumn('last_failure_offline');
        });
    }
};
