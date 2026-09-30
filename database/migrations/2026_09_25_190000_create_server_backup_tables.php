<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The online service's own nightly backups: one row per encrypted dump the
 * server cron writes, with where each copy went (Google Drive, the operator's
 * PC), and the single Google account the platform sends those copies to.
 *
 * Separate from the per-cabinet desktop backups (`backup_records`,
 * `drive_backup_connections`): those protect one clinic's local database,
 * these protect every clinic's data held on the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 120)->unique();
            // Where the cron left the file, so the back office can resend it.
            $table->string('path', 500);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('database_driver', 20);
            $table->string('drive_status', 20)->default('pending');
            $table->string('drive_file_id', 200)->nullable();
            $table->timestamp('drive_uploaded_at')->nullable();
            $table->string('drive_error', 255)->nullable();
            $table->timestamp('pc_copied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_drive_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('folder_id', 200)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_drive_connections');
        Schema::dropIfExists('server_backup_runs');
    }
};
