<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->foreignId('license_type_id')->nullable()->after('plan')->constrained('license_types')->nullOnDelete();
        });

        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->foreignId('license_type_id')->nullable()->after('plan')->constrained('license_types')->nullOnDelete();
            $table->unsignedInteger('duration_days')->nullable()->after('license_type_id');
            $table->string('type_name')->nullable()->after('duration_days');
            $table->string('plan', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hosted_license_grants', function (Blueprint $table): void {
            $table->dropForeign(['license_type_id']);
            $table->dropColumn(['license_type_id', 'duration_days', 'type_name']);
            $table->string('plan', 20)->nullable(false)->change();
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropForeign(['license_type_id']);
            $table->dropColumn('license_type_id');
        });
    }
};
