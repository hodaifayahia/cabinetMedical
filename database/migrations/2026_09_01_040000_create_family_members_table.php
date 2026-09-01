<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Family circle of a patient account. A row is either a dependent profile
     * (linked_user_id null, demographics stored inline) or a link to another
     * patient account awaiting or holding approval.
     */
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relation', 20);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth', 150)->nullable();
            $table->unsignedTinyInteger('wilaya_code')->nullable();
            $table->foreignId('baladiya_id')->nullable()->constrained('baladiyas')->nullOnDelete();
            $table->timestamps();

            $table->index('owner_user_id');
            $table->unique(['owner_user_id', 'linked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
