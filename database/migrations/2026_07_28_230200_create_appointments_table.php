<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-doctor cabinet: appointments belong to the cabinet, so there is
        // no doctor_id column. Every appointment implicitly belongs to the one doctor.
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->date('appointment_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 32)->default('scheduled');
            $table->text('reason')->nullable();
            $table->text('reception_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'starts_at']);
            $table->index('status');
            $table->index('appointment_date');
            $table->index(['status', 'starts_at', 'ends_at'], 'appointments_conflict_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
