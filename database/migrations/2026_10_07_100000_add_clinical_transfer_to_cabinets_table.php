<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a cabinet's records were moved from the online service to its PC,
 * and to which installation. Set on the online service only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cabinets', 'clinical_data_transferred_at')) {
            return;
        }

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->timestamp('clinical_data_transferred_at')->nullable();
            $table->string('clinical_data_transferred_to', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn(['clinical_data_transferred_at', 'clinical_data_transferred_to']);
        });
    }
};
