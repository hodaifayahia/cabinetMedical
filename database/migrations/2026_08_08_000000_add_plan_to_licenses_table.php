<?php

use App\Enums\LicensePlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            // Nullable by design: signed legacy desktop licences continue to
            // derive their edition and expiry exclusively from certificates.
            $table->string('plan', 20)->nullable()->after('edition')->index();
        });

        // Hosted licences issued before plans existed were all perpetual.
        DB::table('licenses')
            ->where('edition', 'hosted')
            ->where('signed_certificate', '')
            ->whereNull('plan')
            ->update(['plan' => LicensePlan::LIFETIME->value]);
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropIndex(['plan']);
            $table->dropColumn('plan');
        });
    }
};
