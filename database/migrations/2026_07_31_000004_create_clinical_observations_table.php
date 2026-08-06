<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50);
            $table->decimal('numeric_value', 10, 2)->nullable();
            $table->string('string_value', 255)->nullable();
            $table->string('unit', 50)->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->string('source', 50)->default('manual');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'type', 'observed_at']);
            $table->index(['encounter_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_observations');
    }
};
