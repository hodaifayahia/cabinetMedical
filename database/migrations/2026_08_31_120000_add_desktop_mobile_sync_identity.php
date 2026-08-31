<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Foundation for two-way appointment sync between a local-first desktop
 * installation and the hosted service the mobile application talks to.
 *
 * Two problems are solved here:
 *
 * 1. `appointment_sync_events.payload` carries `patient_id`, which is the
 *    auto-increment key of whichever installation published it. That number is
 *    meaningless to any other installation. `patients.public_id` gives a
 *    patient one stable identity across the desktop, the cloud, and the mobile
 *    app, so an imported appointment can be attached to the patient row that
 *    already exists locally instead of duplicating them.
 *
 * 2. Sync must be resumable and must never replay work it already did.
 *    `sync_states` records, per cabinet and per remote endpoint, how far the
 *    pull and push streams have advanced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        // Existing patients get an identity now so the first sync after an
        // upgrade can match them instead of creating duplicates.
        DB::table('patients')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($patients): void {
                foreach ($patients as $patient) {
                    DB::table('patients')
                        ->where('id', $patient->id)
                        ->update(['public_id' => (string) Str::uuid7()]);
                }
            });

        Schema::create('sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();

            // The remote origin this cabinet syncs with. Stored in full for
            // diagnostics, and hashed for the unique index so a long URL cannot
            // overflow the key length limit on MySQL.
            $table->string('endpoint', 255);
            $table->char('endpoint_sha256', 64);
            $table->string('stream', 40);

            // How far each direction has advanced. `pull_cursor` is the remote
            // event cursor already imported; `push_cursor` is the local event
            // id already delivered.
            $table->unsignedBigInteger('pull_cursor')->default(0);
            $table->unsignedBigInteger('push_cursor')->default(0);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['cabinet_id', 'endpoint_sha256', 'stream'],
                'sync_states_cabinet_endpoint_stream_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
