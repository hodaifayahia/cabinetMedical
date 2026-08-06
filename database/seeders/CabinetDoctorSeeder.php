<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;

class CabinetDoctorSeeder extends Seeder
{
    /**
     * Seed the single cabinet doctor: user account, doctor profile, and the
     * weekly working hours used for appointment scheduling.
     */
    public function run(): void
    {
        $user = User::query()->where('email', 'doctor@example.com')->first();

        if (! $user instanceof User) {
            if (app()->isProduction()
                || ! (bool) config('medismart.development.seed_demo_user', false)) {
                return;
            }

            $user = User::factory()->create([
                'name' => 'Cabinet Doctor',
                'email' => 'doctor@example.com',
            ]);
        }

        if (! $user->hasRole(RoleName::DOCTOR->value)) {
            $user->assignRole(RoleName::DOCTOR->value);
        }

        $duration = (int) config('clinic.appointments.default_duration', 30);

        $doctor = DoctorProfile::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'specialty' => 'General Medicine',
                'professional_identifier' => 'LIC-000001',
                'consultation_duration' => $duration,
                'consultation_fee_minor' => 200000,
                'is_active' => true,
            ],
        );

        $workingDays = [
            Weekday::MONDAY,
            Weekday::TUESDAY,
            Weekday::WEDNESDAY,
            Weekday::THURSDAY,
            Weekday::FRIDAY,
        ];

        foreach ($workingDays as $day) {
            DoctorSchedule::query()->updateOrCreate(
                [
                    'doctor_id' => $doctor->getKey(),
                    'day_of_week' => $day->value,
                    'starts_at' => '09:00:00',
                    'ends_at' => '17:00:00',
                ],
                [
                    'slot_duration' => $duration,
                    'is_active' => true,
                ],
            );
        }
    }
}
