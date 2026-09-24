<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential, gap-free receipt numbers per cabinet and fiscal year
 * (e.g. FACT-2026-00042) instead of exposing database ids on receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $table->string('scope', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['cabinet_id', 'scope', 'year']);
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->string('receipt_number', 40)->nullable()->after('payment_settled_at');
            $table->index('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropIndex(['receipt_number']);
            $table->dropColumn('receipt_number');
        });

        Schema::dropIfExists('document_sequences');
    }
};
