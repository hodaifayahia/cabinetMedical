<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->string('doctor_name')->nullable();
            $table->string('specialty_code', 100)->nullable()->index();
            $table->string('medical_order_number', 120)->nullable();
            $table->string('clinic_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('full_address', 500)->nullable();
            $table->string('footer_extra_line', 500)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamp('specialty_locked_at')->nullable();

            $table->unique('medical_order_number', 'doctor_profiles_medical_order_number_unique');
        });

        $cabinet = Schema::hasTable('cabinet_settings')
            ? DB::table('cabinet_settings')->orderBy('id')->first()
            : null;

        DB::table('doctor_profiles')->orderBy('id')->each(function (object $doctor) use ($cabinet): void {
            $user = DB::table('users')->where('id', $doctor->user_id)->first();
            $specialty = trim((string) $doctor->specialty);

            DB::table('doctor_profiles')->where('id', $doctor->id)->update([
                'doctor_name' => $user?->name,
                'specialty_code' => $specialty === '' ? null : Str::slug($specialty, '_'),
                'medical_order_number' => $doctor->professional_identifier,
                'clinic_name' => $cabinet?->name,
                'phone' => $cabinet?->phone,
                'email' => $cabinet?->email,
                'city' => $cabinet?->city,
                'full_address' => $cabinet?->address,
                'footer_extra_line' => $cabinet?->prescription_footer,
                'logo_path' => $cabinet?->logo_path,
                'specialty_locked_at' => $specialty === '' ? null : now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropUnique('doctor_profiles_medical_order_number_unique');
            $table->dropIndex(['specialty_code']);
            $table->dropColumn([
                'doctor_name',
                'specialty_code',
                'medical_order_number',
                'clinic_name',
                'phone',
                'email',
                'city',
                'full_address',
                'footer_extra_line',
                'logo_path',
                'specialty_locked_at',
            ]);
        });
    }
};
