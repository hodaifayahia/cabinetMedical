<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the portable booking provenance an appointment arrived with.
     *
     * `booked_by_user_id` and `family_member_id` are this installation's own
     * auto-increment keys and cannot cross a sync boundary, so an imported
     * appointment keeps them null and carries the sender's provenance here
     * instead: the channel, the person the visit is for, and the person
     * reception would call about it.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->json('booking_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('booking_context');
        });
    }
};
