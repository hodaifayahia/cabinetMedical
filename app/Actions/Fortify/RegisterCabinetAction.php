<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Auth\DesktopPinService;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\Wilayas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Provisions a brand-new cabinet in the pending state together with its owner
 * account, doctor profile, default weekly schedule and per-cabinet settings.
 *
 * The action is intentionally free of any request/session coupling so it can be
 * reused from controllers, console commands or the forthcoming API layer.
 */
class RegisterCabinetAction
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly MedicalSpecialtyCatalog $specialties,
        private readonly DesktopPinService $desktopPins,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): User
    {
        $data = Validator::make($input, [
            ...$this->profileRules(),
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{7,24}$/'],
            'cabinet_name' => ['required', 'string', 'min:2', 'max:180'],
            'specialization' => ['required', 'string', 'min:2', 'max:150'],
            'wilaya' => ['required', 'integer', 'between:'.Wilayas::MIN.','.Wilayas::MAX],
            'password' => $this->passwordRules(),
            'device_token' => [
                'nullable',
                'required_with:pin',
                'string',
                'min:32',
                'max:255',
                'regex:/\A[A-Za-z0-9_-]+\z/D',
            ],
            'pin' => [
                'nullable',
                'required_with:device_token',
                'string',
                'confirmed',
                'regex:/\A[0-9]{4}\z/D',
            ],
            'pin_confirmation' => ['nullable', 'required_with:pin', 'string'],
            'device_name' => [
                'nullable',
                'required_with:pin',
                'string',
                'min:2',
                'max:120',
                'different:device_token',
                'different:pin',
            ],
        ], [
            'phone.regex' => 'Saisissez un numéro de téléphone valide.',
            'pin.confirmed' => 'La confirmation du code PIN ne correspond pas.',
            'pin.regex' => 'Le code PIN doit contenir exactement 4 chiffres.',
        ])->validate();

        return DB::transaction(function () use ($data): User {
            $specialty = trim((string) $data['specialization']);
            $phone = trim((string) $data['phone']);

            $cabinet = Cabinet::query()->create([
                'name' => trim((string) $data['cabinet_name']),
                'status' => CabinetStatus::PENDING,
                'specialization' => $this->specialties->display($specialty),
                'wilaya_code' => (int) $data['wilaya'],
            ]);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'cabinet_id' => $cabinet->getKey(),
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'approved_at' => now(),
            ])->save();
            $user->assignRole(RoleName::ADMINISTRATOR->value);

            $cabinet->forceFill(['owner_user_id' => $user->getKey()])->save();

            // Materialise the per-cabinet settings row from configuration.
            CabinetSetting::query()->create([
                ...CabinetSetting::defaults(),
                'cabinet_id' => $cabinet->getKey(),
                'name' => $cabinet->name,
                'phone' => $phone,
                'email' => $data['email'],
            ]);

            $this->provisionDoctorProfile($user, $cabinet, $specialty, $phone);

            if (isset($data['device_token'], $data['pin'], $data['device_name'])) {
                $this->desktopPins->enroll(
                    $user,
                    (string) $data['device_token'],
                    (string) $data['pin'],
                    trim((string) $data['device_name']),
                );
            }

            AuditLog::record(
                'cabinet.registered',
                $cabinet,
                [
                    'owner_user_id' => $user->getKey(),
                    'wilaya_code' => $cabinet->wilaya_code,
                    'specialty_code' => $user->doctorProfile?->specialty_code,
                ],
                $user->getKey(),
            );

            return $user;
        });
    }

    private function provisionDoctorProfile(
        User $user,
        Cabinet $cabinet,
        string $specialty,
        string $phone,
    ): void {
        $duration = (int) config('clinic.appointments.default_duration', 30);

        $doctor = new DoctorProfile([
            'user_id' => $user->getKey(),
            'doctor_name' => $user->name,
            'specialty' => $this->specialties->display($specialty),
            'specialty_code' => $this->specialties->codeFor($specialty),
            'professional_identifier' => null,
            'clinic_name' => $cabinet->name,
            'phone' => $phone,
            'email' => $user->email,
            'consultation_duration' => $duration,
            'consultation_fee_minor' => 0,
            'is_active' => true,
        ]);
        $doctor->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        foreach ([
            Weekday::MONDAY,
            Weekday::TUESDAY,
            Weekday::WEDNESDAY,
            Weekday::THURSDAY,
            Weekday::FRIDAY,
        ] as $day) {
            $schedule = new DoctorSchedule([
                'doctor_id' => $doctor->getKey(),
                'day_of_week' => $day->value,
                'starts_at' => '09:00:00',
                'ends_at' => '17:00:00',
                'slot_duration' => $duration,
                'is_active' => true,
            ]);
            $schedule->forceFill(['cabinet_id' => $cabinet->getKey()])->save();
        }
    }
}
