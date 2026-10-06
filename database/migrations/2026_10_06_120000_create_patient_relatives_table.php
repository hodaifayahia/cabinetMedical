<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family links between two dossiers of the same cabinet (« Ali est le frère
 * de Sara »). Each link is stored in both directions, so a patient's
 * relatives are always read from their own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_relatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('relative_patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('relation', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['patient_id', 'relative_patient_id']);
            $table->index('relative_patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_relatives');
    }
};
