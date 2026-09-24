<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient's vaccination record (carnet de vaccination).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_vaccinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('vaccine', 120);
            $table->string('dose', 60)->nullable();
            // Slot of the national calendar this dose fulfils, e.g. "m2:penta".
            $table->string('schedule_key', 40)->nullable();
            $table->date('given_on');
            $table->string('lot', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'given_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_vaccinations');
    }
};
