<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cabinet medication reference catalogue (used by prescriptions).
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('dci', 200)->nullable();
            $table->string('form', 100)->nullable();
            $table->string('dosage', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('dci');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
