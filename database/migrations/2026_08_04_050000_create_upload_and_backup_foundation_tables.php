<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_token_hash', 64)->unique();
            $table->string('mode', 20);
            $table->string('purpose', 80)->default('medical_document');
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('maximum_files')->default(10);
            $table->unsignedBigInteger('maximum_individual_bytes');
            $table->unsignedBigInteger('maximum_total_bytes');
            $table->json('allowed_mime_types');
            $table->string('status', 30)->default('pending');
            $table->string('source_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('uploaded_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_session_id');
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('disk', 80)->default('local');
            $table->string('path', 1000);
            $table->string('mime_type', 190);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('status', 30)->default('quarantined');
            $table->timestamp('uploaded_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('upload_session_id')->references('id')->on('upload_sessions')->restrictOnDelete();
            $table->index(['upload_session_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index('sha256');
        });

        Schema::create('backup_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('filename');
            $table->string('disk', 80)->default('local');
            $table->string('local_path', 1000)->nullable();
            $table->string('remote_file_id')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->string('application_version', 50);
            $table->string('status', 30)->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('remote_file_id');
        });

        Schema::create('cloud_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('account_email')->nullable();
            $table->text('encrypted_access_token')->nullable();
            $table->text('encrypted_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('folder_id')->nullable();
            $table->string('folder_name')->nullable();
            $table->string('status', 30)->default('disconnected');
            $table->timestamp('last_connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_connections');
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('uploaded_documents');
        Schema::dropIfExists('upload_sessions');
    }
};
