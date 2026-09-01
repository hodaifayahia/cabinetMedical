<?php

namespace Database\Seeders;

use App\Enums\CabinetStatus;
use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\Baladiya;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\CabinetSetting;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Demo fixtures for developing the mobile client against a live server.
 *
 * Deliberately NOT registered in DatabaseSeeder: it creates accounts with
 * known passwords, so it must only ever run when a developer asks for it
 * explicitly, against a throwaway database:
 *
 *   php artisan db:seed --class=MobileDemoSeeder
 *
 * It refuses to run in production.
 */
class MobileDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('MobileDemoSeeder refuses to run in production.');

            return;
        }

        $baladiya = Baladiya::query()->where('wilaya_code', 16)->orderBy('id')->first();

        // --- Clinic owner (doctor) -------------------------------------------------
        $doctorUser = User::query()->firstOrCreate(
            ['email' => 'doctor@clickdz.test'],
            [
                'name' => 'Karim Boudjema',
                'phone' => '0550000001',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'approved_at' => now(),
            ],
        );

        $cabinet = Cabinet::query()->firstOrCreate(
            ['owner_user_id' => $doctorUser->getKey()],
            [
                'name' => 'عيادة الأمل',
                'status' => CabinetStatus::ACTIVE,
                'specialization' => 'Pédiatrie',
                'wilaya_code' => 16,
                'activated_at' => now(),
            ],
        );

        if ($cabinet->status !== CabinetStatus::ACTIVE) {
            $cabinet->forceFill(['status' => CabinetStatus::ACTIVE, 'activated_at' => now()])->save();
        }

        $doctorUser->forceFill(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()])->save();

        if (! $doctorUser->hasRole(RoleName::DOCTOR->value)) {
            $doctorUser->assignRole(RoleName::DOCTOR->value);
        }

        CabinetSetting::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->getKey()],
            [
                'name' => 'عيادة الأمل',
                'address' => 'طريق العطف، حيدرة',
                'city' => 'Alger',
                'phone' => '023456789',
                'timezone' => 'Africa/Algiers',
                'default_appointment_duration' => 30,
            ],
        );

        // --- Reception ------------------------------------------------------------
        $receptionUser = User::query()->firstOrCreate(
            ['email' => 'reception@clickdz.test'],
            [
                'name' => 'Nadia Cherif',
                'phone' => '0550000002',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $receptionUser->forceFill(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()])->save();

        if (! $receptionUser->hasRole(RoleName::ASSISTANT->value)) {
            $receptionUser->assignRole(RoleName::ASSISTANT->value);
        }

        // --- Doctor profile, hours, open months -----------------------------------
        $doctor = DoctorProfile::withoutCabinetScope()->updateOrCreate(
            ['user_id' => $doctorUser->getKey()],
            [
                'cabinet_id' => $cabinet->getKey(),
                'specialty' => 'Pédiatrie',
                'doctor_name' => 'Dr Karim Boudjema',
                'clinic_name' => 'عيادة الأمل',
                'phone' => '0550000001',
                'city' => 'Alger',
                'consultation_duration' => 30,
                'consultation_fee_minor' => 200000,
                'is_active' => true,
            ],
        );

        // Saturday..Thursday, morning + evening. Friday closed.
        $workdays = [
            Weekday::SATURDAY,
            Weekday::SUNDAY,
            Weekday::MONDAY,
            Weekday::TUESDAY,
            Weekday::WEDNESDAY,
            Weekday::THURSDAY,
        ];

        DoctorSchedule::withoutCabinetScope()->where('doctor_id', $doctor->getKey())->delete();

        foreach ($workdays as $day) {
            foreach ([['09:00', '12:00'], ['17:30', '21:00']] as [$from, $to]) {
                DoctorSchedule::withoutCabinetScope()->create([
                    'cabinet_id' => $cabinet->getKey(),
                    'doctor_id' => $doctor->getKey(),
                    'day_of_week' => $day,
                    'starts_at' => $from,
                    'ends_at' => $to,
                    'slot_duration' => 30,
                    'is_active' => true,
                ]);
            }
        }

        // Open the current month and the next two so the app has bookable days.
        $cursor = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            DoctorOpenMonth::withoutCabinetScope()->updateOrCreate(
                [
                    'doctor_id' => $doctor->getKey(),
                    'year' => (int) $cursor->year,
                    'month' => (int) $cursor->month,
                ],
                ['cabinet_id' => $cabinet->getKey(), 'is_open' => true],
            );

            $cursor = $cursor->addMonth();
        }

        // --- Public directory listing ----------------------------------------------
        CabinetPublicProfile::withoutCabinetScope()->updateOrCreate(
            ['cabinet_id' => $cabinet->getKey()],
            [
                'is_listed' => true,
                'about' => "عيادة متخصصة في طب الأطفال والحساسية.\nرئيس مصلحة سابقا.",
                'address' => 'طريق العطف، حيدرة، الجزائر',
                'baladiya_id' => $baladiya?->getKey(),
                'phones' => ['023456789', '0550000001'],
                'latitude' => 36.7460000,
                'longitude' => 3.0430000,
                'photos' => [],
            ],
        );

        // --- Demo patient ------------------------------------------------------------
        $patient = User::query()->firstOrCreate(
            ['phone' => '0660000001'],
            [
                'name' => 'Amine Benali',
                'email' => 'patient@clickdz.test',
                'password' => Hash::make('password'),
                'approved_at' => now(),
            ],
        );

        if (! $patient->hasRole(RoleName::PATIENT->value)) {
            $patient->assignRole(RoleName::PATIENT->value);
        }

        PatientProfile::query()->updateOrCreate(
            ['user_id' => $patient->getKey()],
            [
                'first_name' => 'Amine',
                'last_name' => 'Benali',
                'gender' => Gender::MALE,
                'date_of_birth' => '1992-04-17',
                'wilaya_code' => 16,
                'baladiya_id' => $baladiya?->getKey(),
            ],
        );

        $this->command?->info('Mobile demo data ready:');
        $this->command?->line('  patient   0660000001 / password');
        $this->command?->line('  doctor    doctor@clickdz.test / password');
        $this->command?->line('  reception reception@clickdz.test / password');
    }
}
