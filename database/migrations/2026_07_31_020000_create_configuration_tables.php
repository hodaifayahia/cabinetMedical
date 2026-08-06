<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Référentiels ---------------------------------------------------------
        Schema::create('bilan_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
            $table->index('category');
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
            $table->index('category');
        });

        Schema::create('practitioners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('specialty', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('order_number', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        // Finance --------------------------------------------------------------
        Schema::create('consultation_fees', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('label');
        });

        Schema::create('acts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->nullable();
            $table->string('name', 200);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
            $table->index('code');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        // Singleton accounting settings row.
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 10)->default('DA');
            $table->unsignedSmallInteger('vat_rate')->default(0);
            $table->unsignedBigInteger('default_consultation_fee_minor')->nullable();
            $table->string('receipt_prefix', 20)->default('FACT-');
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_settings');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('acts');
        Schema::dropIfExists('consultation_fees');
        Schema::dropIfExists('practitioners');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('bilan_types');
    }
};
