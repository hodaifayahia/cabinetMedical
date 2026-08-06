<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_records', function (Blueprint $table): void {
            $table->string('drive_upload_status', 24)->nullable()->after('remote_file_id');
            $table->unsignedBigInteger('drive_upload_bytes')->default(0)->after('drive_upload_status');
            $table->unsignedSmallInteger('drive_upload_attempts')->default(0)->after('drive_upload_bytes');
            $table->string('drive_upload_failure_code', 64)->nullable()->after('drive_upload_attempts');
            $table->timestamp('drive_upload_cancel_requested_at')->nullable()->after('drive_upload_failure_code');
            $table->timestamp('drive_upload_updated_at')->nullable()->after('drive_upload_cancel_requested_at');
            $table->index(
                ['drive_upload_status', 'drive_upload_updated_at'],
                'backup_records_drive_upload_state_index',
            );
        });

        DB::table('backup_records')
            ->whereNotNull('remote_file_id')
            ->update([
                'drive_upload_status' => 'completed',
                'drive_upload_bytes' => DB::raw('COALESCE(size, 0)'),
                'drive_upload_updated_at' => DB::raw('COALESCE(completed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('backup_records', function (Blueprint $table): void {
            $table->dropIndex('backup_records_drive_upload_state_index');
            $table->dropColumn([
                'drive_upload_status',
                'drive_upload_bytes',
                'drive_upload_attempts',
                'drive_upload_failure_code',
                'drive_upload_cancel_requested_at',
                'drive_upload_updated_at',
            ]);
        });
    }
};
