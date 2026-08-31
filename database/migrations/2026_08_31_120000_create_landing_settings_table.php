<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            // "*" applies to every language; otherwise ar / fr / en.
            $table->string('locale', 8)->default('*');
            $table->text('value');
            $table->timestamps();

            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_settings');
    }
};
