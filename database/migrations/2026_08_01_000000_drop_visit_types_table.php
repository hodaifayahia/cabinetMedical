<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('visit_types');
    }

    public function down(): void
    {
        Schema::create('visit_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });
    }
};
