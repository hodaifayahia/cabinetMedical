<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of place this is, so the patient app's doctor / clinic /
     * radiology tabs can filter on something real instead of being decorative.
     *
     * Defaults to `doctor`: every cabinet that exists today is a doctor's
     * practice, and an admin reclassifies the exceptions.
     */
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->string('facility_type', 20)->default('doctor')->index();
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn('facility_type');
        });
    }
};
