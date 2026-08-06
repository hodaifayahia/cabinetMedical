<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_placeholder_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('key', 80)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('model_placeholder_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')
                ->constrained('model_placeholder_sections')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key', 80);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['section_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_placeholder_attributes');
        Schema::dropIfExists('model_placeholder_sections');
    }
};
