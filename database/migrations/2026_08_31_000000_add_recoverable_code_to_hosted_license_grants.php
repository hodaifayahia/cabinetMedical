<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A platform admin generates codes in batches and hands them out over
     * days, so a write-only digest is not enough operationally. The keyed
     * hash stays the sole lookup path; this column only lets an authorised
     * platform admin read a code back, encrypted at rest with APP_KEY.
     */
    public function up(): void
    {
        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->text('code_encrypted')->nullable()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->dropColumn('code_encrypted');
        });
    }
};
