<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('payment_service', 180)->nullable()->after('payment_method');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('cabinet_setting_id')
                ->nullable()
                ->after('password')
                ->constrained('cabinet_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cabinet_setting_id');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('payment_service');
        });
    }
};
