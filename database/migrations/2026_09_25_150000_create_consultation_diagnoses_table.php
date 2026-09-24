<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIM-10 codes attached to a consultation, next to the free-text
 * diagnosis, for searchable history and disease statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_diagnoses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('code', 12);
            $table->string('label', 255);
            $table->timestamps();

            $table->unique(['consultation_id', 'code']);
            $table->index(['cabinet_id', 'code']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_diagnoses');
    }
};
