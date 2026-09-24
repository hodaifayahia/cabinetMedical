<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient's safety list: allergies (checked against every ordonnance),
 * chronic conditions and long-term treatments, shown as a banner wherever
 * the patient is opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 180);
            $table->string('severity', 20)->nullable();
            $table->string('details', 500)->nullable();
            $table->date('since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_alerts');
    }
};
