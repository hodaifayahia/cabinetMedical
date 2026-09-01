<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public-facing directory listing of a cabinet. A cabinet appears in the
     * mobile discovery endpoints only when its profile exists and is_listed.
     */
    public function up(): void
    {
        Schema::create('cabinet_public_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->unique()->constrained('cabinets')->cascadeOnDelete();
            $table->boolean('is_listed')->default(false)->index();
            $table->text('about')->nullable();
            $table->string('address', 255)->nullable();
            $table->foreignId('baladiya_id')->nullable()->constrained('baladiyas')->nullOnDelete();
            $table->json('phones')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_public_profiles');
    }
};
