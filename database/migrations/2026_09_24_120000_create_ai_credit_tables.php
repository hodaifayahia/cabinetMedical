<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI credit wallet per cabinet, the ledger of every spend and recharge, and
 * the stored results a doctor can reopen without paying twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', static function (Blueprint $table): void {
            // Every cabinet, existing ones included, starts with the same wallet.
            $table->unsignedInteger('ai_credits')->default((int) config('ai.initial_credits', 500));
            $table->boolean('ai_enabled')->default(true);
        });

        Schema::create('ai_usages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature', 40);
            // Negative for a spend, positive for a recharge or a refund.
            $table->integer('credits');
            $table->integer('balance_after');
            $table->string('status', 20);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['cabinet_id', 'created_at']);
        });

        Schema::create('ai_insights', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('ai_usages');

        Schema::table('cabinets', static function (Blueprint $table): void {
            $table->dropColumn(['ai_credits', 'ai_enabled']);
        });
    }
};
