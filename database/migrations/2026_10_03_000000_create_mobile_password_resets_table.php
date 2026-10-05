<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Six-digit reset codes for the mobile "Forgot password" flow. One live code
 * per account: a new request replaces the old row. Only the hash is stored,
 * and a code dies after a few wrong guesses or once it expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_password_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_password_resets');
    }
};
