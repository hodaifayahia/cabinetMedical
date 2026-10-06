<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Keyed hashes of the one-time recovery codes; never the codes.
            $table->text('account_recovery_codes')->nullable()->after('local_pin_hash');
            $table->timestamp('account_recovery_codes_generated_at')->nullable()->after('account_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['account_recovery_codes', 'account_recovery_codes_generated_at']);
        });
    }
};
