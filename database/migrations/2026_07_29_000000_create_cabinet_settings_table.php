<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-doctor cabinet: exactly one settings row (a singleton).
        Schema::create('cabinet_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('currency_code', 3)->default('DZD');
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('default_appointment_duration')->default(30);
            $table->unsignedBigInteger('default_consultation_fee_minor')->default(0);
            $table->text('receipt_footer')->nullable();
            $table->text('prescription_footer')->nullable();
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cabinet_settings');
    }
};
