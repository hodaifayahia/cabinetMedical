<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The demographic identity of a mobile patient account. One row per user;
     * cabinet-side dossiers (patients table) stay separate per tenant.
     */
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 20);
            $table->date('date_of_birth');
            $table->string('place_of_birth', 150)->nullable();
            $table->unsignedTinyInteger('wilaya_code')->nullable();
            $table->foreignId('baladiya_id')->nullable()->constrained('baladiyas')->nullOnDelete();
            $table->string('avatar_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_profiles');
    }
};
