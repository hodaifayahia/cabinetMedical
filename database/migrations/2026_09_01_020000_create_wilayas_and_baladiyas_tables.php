<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Algerian administrative geography referenced by patient profiles,
     * family members, and public cabinet profiles. Wilayas keep their official
     * code (1..58) as primary key.
     */
    public function up(): void
    {
        Schema::create('wilayas', function (Blueprint $table): void {
            $table->unsignedTinyInteger('code')->primary();
            $table->string('name_fr', 100);
            $table->string('name_ar', 100);
            $table->timestamps();
        });

        Schema::create('baladiyas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('wilaya_code');
            $table->string('name_fr', 120);
            $table->string('name_ar', 120);
            $table->timestamps();

            $table->foreign('wilaya_code')->references('code')->on('wilayas')->cascadeOnDelete();
            $table->unique(['wilaya_code', 'name_fr']);
            $table->index('wilaya_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baladiyas');
        Schema::dropIfExists('wilayas');
    }
};
