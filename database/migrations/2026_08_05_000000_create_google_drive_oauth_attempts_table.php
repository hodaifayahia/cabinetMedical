<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_drive_oauth_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('state_sha256', 64)->unique();
            $table->text('encrypted_pkce_verifier')->nullable();
            $table->string('redirect_uri', 1000);
            $table->foreignId('cabinet_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['cabinet_setting_id', 'actor_id', 'status'], 'drive_oauth_attempt_actor_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_oauth_attempts');
    }
};
