<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly MedicalSpecialtyCatalog $specialties,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'specialty' => ['required', 'string', 'min:2', 'max:150'],
            'password' => $this->passwordRules(),
        ])->validate();

        $lock = Cache::lock('medismart:first-owner-registration', 30);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'email' => 'La création du compte initial est déjà en cours.',
            ]);
        }

        try {
            return DB::transaction(function () use ($input): User {
                if (User::query()->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'Le compte initial existe déjà. Connectez-vous ou demandez à un administrateur de créer un membre du personnel.',
                    ]);
                }

                $user = User::query()->create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                    'cabinet_setting_id' => CabinetSetting::current()->getKey(),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
                $this->provisionDoctorProfile($user, trim($input['specialty']));
                AuditLog::record(
                    'installation.initial_owner_created',
                    $user,
                    [
                        'source' => 'first_run',
                        'specialty_code' => $user->doctorProfile?->specialty_code,
                    ],
                    $user->getKey(),
                );

                return $user;
            });
        } finally {
            $lock->release();
        }
    }

    private function provisionDoctorProfile(User $user, string $specialty): void
    {
        $duration = (int) config('clinic.appointments.default_duration', 30);
        $doctor = DoctorProfile::query()->create([
            'user_id' => $user->getKey(),
            'doctor_name' => $user->name,
            'specialty' => $this->specialties->display($specialty),
            'specialty_code' => $this->specialties->codeFor($specialty),
            'professional_identifier' => null,
            'clinic_name' => (string) config('clinic.name', 'MediSmart'),
            'consultation_duration' => $duration,
            'consultation_fee_minor' => 0,
            'is_active' => true,
        ]);

        foreach ([
            Weekday::MONDAY,
            Weekday::TUESDAY,
            Weekday::WEDNESDAY,
            Weekday::THURSDAY,
            Weekday::FRIDAY,
        ] as $day) {
            DoctorSchedule::query()->create([
                'doctor_id' => $doctor->getKey(),
                'day_of_week' => $day->value,
                'starts_at' => '09:00:00',
                'ends_at' => '17:00:00',
                'slot_duration' => $duration,
                'is_active' => true,
            ]);
        }
    }
}
