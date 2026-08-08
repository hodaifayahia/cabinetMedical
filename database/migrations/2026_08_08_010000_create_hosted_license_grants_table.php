<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_license_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan', 20);
            $table->char('code_hash', 64)->unique();
            $table->char('code_suffix', 4);
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['cabinet_id', 'redeemed_at', 'revoked_at'], 'hosted_license_grants_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_license_grants');
    }
};
