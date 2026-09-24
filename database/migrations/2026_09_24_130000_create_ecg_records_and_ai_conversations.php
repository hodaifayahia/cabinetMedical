<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ECG tracings with their measurements, AI reading, questions asked about
 * them and the doctor's validated conclusion; and the copilot conversations
 * kept as the audit trail of what the assistant proposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecg_records', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->string('title', 200);
            $table->date('recorded_at');
            $table->string('file_path');
            $table->string('original_filename', 190)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // Taken by the software from the trace (rate, RR intervals…).
            $table->json('measurements')->nullable();
            // The AI reading, kept apart from what the doctor signs.
            $table->json('analysis')->nullable();
            $table->json('conversation')->nullable();
            $table->text('doctor_conclusion')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'recorded_at']);
        });

        Schema::create('ai_conversations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->json('messages');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consultation_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ecg_records');
    }
};
