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
        // A month must be explicitly opened by the doctor before it accepts
        // any bookings. Working days (doctor_schedules) and closures
        // (doctor_time_off) only take effect inside an opened month.
        Schema::create('doctor_open_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctor_profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->boolean('is_open')->default(true);
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['doctor_id', 'year', 'month'], 'doctor_open_months_period_unique');
            $table->index(['doctor_id', 'year', 'month', 'is_open'], 'doctor_open_months_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_open_months');
    }
};
