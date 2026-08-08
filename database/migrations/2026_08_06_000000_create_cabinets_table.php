<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The central tenant record. Each cabinet groups one clinic's users and
     * domain data. Cabinets are provisioned in a pending state and activated
     * by platform staff through the Filament fulfillment panel.
     */
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('pending')->index();
            $table->string('specialization')->nullable();
            $table->unsignedTinyInteger('wilaya_code')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};
