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
        // Single-doctor cabinet: exactly one active doctor profile is expected.
        Schema::create('doctor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('specialty', 150);
            $table->string('professional_identifier', 120)->nullable()->unique();
            $table->unsignedSmallInteger('consultation_duration')->nullable();
            $table->unsignedBigInteger('consultation_fee_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('specialty');
        });

        // The single doctor's weekly working hours (used for cabinet scheduling).
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctor_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('slot_duration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['doctor_id', 'day_of_week', 'is_active'], 'doctor_schedules_lookup_index');
            $table->unique(['doctor_id', 'day_of_week', 'starts_at', 'ends_at'], 'doctor_schedules_slot_unique');
        });

        // The single doctor's time off / cabinet closures.
        Schema::create('doctor_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctor_profiles')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->string('reason', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'starts_at']);
            $table->index(['doctor_id', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_time_off');
        Schema::dropIfExists('doctor_schedules');
        Schema::dropIfExists('doctor_profiles');
    }
};
