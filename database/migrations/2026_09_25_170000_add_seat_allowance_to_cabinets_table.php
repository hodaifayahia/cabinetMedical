<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seats become a per-cabinet allowance the platform sells, instead of one
 * fixed limit for everybody.
 *
 * - `seat_limit`: accounts the cabinet may hold, the doctor included. A new
 *   cabinet gets two: the doctor plus one colleague.
 * - `seat_price`: what this cabinet pays per seat, in dinars. Back-office
 *   information only; nothing is charged from it.
 * - `seat_limit_synced_at`: on a local desktop, when the allowance was last
 *   confirmed by the online service. Always null on the online service itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('seat_limit')->default(2);
            $table->unsignedInteger('seat_price')->nullable();
            $table->timestamp('seat_limit_synced_at')->nullable();
        });

        // Cabinets that already exist keep the three seats every cabinet had
        // under the old fixed limit; nobody loses a seat they were using.
        DB::table('cabinets')->update(['seat_limit' => 3]);
    }

    public function down(): void
    {
        Schema::table('cabinets', static function (Blueprint $table): void {
            $table->dropColumn(['seat_limit', 'seat_price', 'seat_limit_synced_at']);
        });
    }
};
