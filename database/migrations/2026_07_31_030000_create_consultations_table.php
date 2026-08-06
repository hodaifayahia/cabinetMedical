<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lightweight visit record captured from the consultation workspace.
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('consulted_at');
            $table->text('motif')->nullable();
            $table->text('examens')->nullable();
            $table->text('diagnostic')->nullable();
            $table->text('traitement')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('in_progress');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'consulted_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
