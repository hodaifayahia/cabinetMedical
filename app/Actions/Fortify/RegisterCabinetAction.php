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
use App\Support\MedicalSpecialtyCatalog;
use App\Support\Wilayas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

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
        ], [
            'phone.regex' => 'Saisissez un numéro de téléphone valide.',
        ])->validate();

        return $this->provision($data);
    }

    /**
     * Create the cabinet and everything that hangs off it, or report clearly
     * that nothing was created.
     *
     * The whole provisioning runs in one transaction, so a failure part-way
     * through removes the cabinet and its owner again. Letting that surface as
     * a generic server error told the owner nothing, and the next screen they
     * reached was a sign-in form that rejected the account they believed they
     * had just created. A registration that did not happen now says so.
     *
     * @param  array<string, mixed>  $data
     */
    private function provision(array $data): User
    {
        try {
            return $this->provisionWithinTransaction($data);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'cabinet_name' => 'La création du cabinet a échoué et rien n’a été enregistré. Aucun compte n’existe pour le moment : réessayez, puis contactez le support Drclick si le problème persiste.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function provisionWithinTransaction(array $data): User
    {
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
            $user->assignRole($this->administratorRole());

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

    /**
     * Resolve the cabinet-owner role, creating it when a deployment has not
     * been seeded yet.
     *
     * Assigning the role by name goes through Spatie's cached lookup and
     * raises RoleDoesNotExist when the roles table is empty or the permission
     * cache is stale. That exception used to escape the surrounding
     * transaction and roll the entire registration back, so the owner was told
     * their cabinet had been created and then rejected at sign-in because no
     * account existed.
     */
    private function administratorRole(): RoleContract
    {
        $roleClass = app(PermissionRegistrar::class)->getRoleClass();

        return $roleClass::findOrCreate(
            RoleName::ADMINISTRATOR->value,
            config('auth.defaults.guard', 'web'),
        );
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
