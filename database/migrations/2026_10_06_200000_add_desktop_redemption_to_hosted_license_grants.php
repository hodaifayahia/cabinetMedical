<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An installed desktop redeems its code on the online service once, then
     * runs offline on the signed entitlement it received. The grant records
     * which installation took it, so a lost response can be retried from
     * that same poste while any other poste is told the code is already used.
     */
    public function up(): void
    {
        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->string('redeemed_installation_id', 64)->nullable()->after('redeemed_at');
            $table->string('redeemed_owner_email', 190)->nullable()->after('redeemed_installation_id');
            $table->index('redeemed_installation_id');
        });
    }

    public function down(): void
    {
        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->dropIndex(['redeemed_installation_id']);
            $table->dropColumn(['redeemed_installation_id', 'redeemed_owner_email']);
        });
    }
};
