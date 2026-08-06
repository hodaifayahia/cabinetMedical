<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_backup_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_setting_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('folder_name')->default('MediSmart Backups');
            $table->string('folder_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_backup_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_backup_connections');
    }
};
