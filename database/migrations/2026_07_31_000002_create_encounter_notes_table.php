<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('section', 50);
            $table->json('content_json')->nullable();
            $table->text('content_text')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->timestamps();

            $table->unique(['encounter_id', 'section', 'revision_number']);
            $table->index(['encounter_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_notes');
    }
};
