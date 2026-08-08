<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_pin_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->char('device_token_hash', 64)->unique();
            $table->string('device_name', 120);
            $table->string('pin_hash');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'cabinet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_pin_credentials');
    }
};
