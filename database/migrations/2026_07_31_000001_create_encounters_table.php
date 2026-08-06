<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->foreignId('amends_encounter_id')->nullable()->constrained('encounters')->nullOnDelete();
            $table->text('amendment_reason')->nullable();
            $table->string('content_hash', 64)->nullable()->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['patient_id', 'occurred_at']);
            $table->index(['provider_id', 'signed_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
