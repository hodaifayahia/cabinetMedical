<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coverage control for the mobile directory.
     *
     * The platform does not go live everywhere at once: an admin switches a
     * wilaya (and individual baladiyas within it) on when there is something
     * worth searching there. Everything ships ACTIVE so the existing
     * directory behaves exactly as before until someone turns a region off.
     */
    public function up(): void
    {
        Schema::table('wilayas', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::table('baladiyas', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->index(['wilaya_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('baladiyas', function (Blueprint $table): void {
            $table->dropIndex(['wilaya_code', 'is_active']);
            $table->dropColumn('is_active');
        });

        Schema::table('wilayas', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
