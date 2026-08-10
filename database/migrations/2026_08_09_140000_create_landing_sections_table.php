<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_sections', static function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 8)->default('fr');
            $table->string('slug');
            $table->string('section_type', 32)->default('content');
            $table->string('eyebrow')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->string('image_url')->nullable();
            $table->json('items')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['locale', 'slug']);
            $table->index(['locale', 'is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_sections');
    }
};
