<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties an appointment to the patient account that booked it (self or on
     * behalf of a family member) and records the booking channel.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained('family_members')->nullOnDelete();
            $table->string('booking_channel', 20)->nullable();

            $table->index('booked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booked_by_user_id');
            $table->dropConstrainedForeignId('family_member_id');
            $table->dropColumn('booking_channel');
        });
    }
};
