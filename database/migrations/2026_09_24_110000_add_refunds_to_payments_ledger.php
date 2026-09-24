<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds are recorded as negative ledger entries that point at the
 * instalment they reverse. Every existing `sum('amount_minor')` therefore
 * nets refunds out without a second code path, and the cash journal keeps
 * the refund in the month the money actually left the cabinet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('amount_minor')->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('refund_of_payment_id')->nullable()->after('consultation_id');
            $table->index('refund_of_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['refund_of_payment_id']);
            $table->dropColumn('refund_of_payment_id');
        });
    }
};
