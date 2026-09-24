<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up recalls (« revoir dans 3 mois : HbA1c ») and tracking of which
 * patients were reminded (appointment reminders, recalls, unpaid balances).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_recalls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->date('due_on');
            $table->string('reason', 255);
            $table->string('status', 20)->default('pending');
            $table->dateTime('contacted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cabinet_id', 'status', 'due_on']);
            $table->index(['patient_id', 'status']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dateTime('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('reminded_at');
        });

        Schema::dropIfExists('patient_recalls');
    }
};
