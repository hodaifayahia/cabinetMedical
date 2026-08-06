<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 160)->unique();
            $table->text('encrypted_value')->nullable();
            $table->text('plain_value')->nullable();
            $table->string('type', 30)->default('string');
            $table->string('group', 80)->default('general');
            $table->timestamps();

            $table->index(['group', 'key']);
        });

        Schema::create('tunnel_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40)->default('cloudflare');
            $table->string('mode', 40)->default('named');
            $table->string('tunnel_id')->nullable();
            $table->string('tunnel_name')->nullable();
            $table->string('hostname')->nullable();
            $table->text('encrypted_tunnel_token')->nullable();
            $table->string('executable_path', 500)->nullable();
            $table->boolean('service_installed')->default(false);
            $table->string('desired_state', 30)->default('stopped');
            $table->string('runtime_state', 30)->default('stopped');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_stopped_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('runtime_state');
            $table->unique(['provider', 'mode'], 'tunnel_settings_provider_mode_unique');
        });

        Schema::create('licenses', function (Blueprint $table): void {
            $table->id();
            $table->string('license_id')->unique();
            $table->string('product', 100);
            $table->string('edition', 80);
            $table->string('customer_id')->nullable()->index();
            $table->longText('signed_certificate');
            $table->string('status', 40)->default('not_activated');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('offline_grace_until')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->json('last_server_response')->nullable();
            $table->timestamps();

            $table->index(['product', 'status']);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('installation_id')->unique();
            $table->string('machine_fingerprint_hash', 64)->index();
            $table->string('label')->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('license_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->uuid('device_id')->nullable();
            $table->uuid('installation_id')->index();
            $table->string('machine_fingerprint_hash', 64);
            $table->timestamp('activated_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->unique(['license_id', 'installation_id']);
            $table->index(['machine_fingerprint_hash', 'status'], 'license_activations_fingerprint_status_index');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('application_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event', 160)->index();
            $table->string('severity', 20)->default('info');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['severity', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('tunnel_settings');
        Schema::dropIfExists('application_settings');
    }
};
