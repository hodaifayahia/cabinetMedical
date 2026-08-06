<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('code_system', 100)->nullable();
            $table->string('display_label', 255);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['encounter_id', 'status']);
            $table->index(['code', 'code_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
