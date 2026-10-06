<?php

namespace App\Services\Cabinet;

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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

/**
 * Completes a pending cabinet with its owner account, doctor profile, default
 * weekly schedule and per-cabinet settings. It can claim a matching cabinet
 * created by a Windows download request before falling back to a new cabinet.
 *
 * Extracted verbatim from RegisterCabinetAction so that web self-registration
 * and the platform-admin mobile API provision clinics through exactly the same
 * code path. The service validates nothing of its own: callers hand it data
 * they have already validated — the web action through its Fortify rules, the
 * admin API through its FormRequest.
 */
class CabinetProvisioningService
{
    public function __construct(
        private readonly MedicalSpecialtyCatalog $specialties,
        private readonly CabinetCatalogueProvisioner $catalogue,
        private readonly CabinetDirectoryListing $listing,
    ) {}

    /**
     * Create or complete the cabinet and everything hanging off it in one
     * transaction, returning the owner account.
     *
     * @param  array{name: string, email: string, password: string, phone: string, cabinet_name: string, specialization: string, wilaya: int|string}  $data
     */
    public function provision(array $data, bool $claimDownloadCabinet = false): User
    {
        return DB::transaction(function () use ($data, $claimDownloadCabinet): User {
            $specialty = trim((string) $data['specialization']);
            $phone = trim((string) $data['phone']);

            // A doctor may have requested the Windows installer before
            // creating an account. In that case the download form already
            // created a pending cabinet so an administrator can issue its
            // activation code. Attach registration to that cabinet by the
            // same email address instead of creating a duplicate.
            //
            // The doctor may also have activated the installed desktop first,
            // with the code issued to that download cabinet: the cabinet is
            // then already active online, still without an owner account.
            // Claiming it (rather than opening a second, pending cabinet) is
            // what lets this account link the desktop for mobile bookings.
            $cabinet = $claimDownloadCabinet
                ? Cabinet::query()
                    ->whereNull('owner_user_id')
                    ->where(fn ($claimable) => $claimable
                        ->where(fn ($pending) => $pending
                            ->where('status', CabinetStatus::PENDING->value)
                            ->whereNull('license_id'))
                        ->orWhere(fn ($activatedFromDesktop) => $activatedFromDesktop
                            ->where('status', CabinetStatus::ACTIVE->value)
                            ->whereHas('hostedLicenseGrants', fn ($grants) => $grants
                                ->whereNotNull('redeemed_installation_id'))))
                    ->whereHas('desktopDownloadLeads', fn ($leads) => $leads
                        ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $data['email']))]))
                    ->latest()
                    ->lockForUpdate()
                    ->first()
                : null;

            $cabinetData = [
                'name' => trim((string) $data['cabinet_name']),
                // An already activated cabinet keeps its status and licence.
                'status' => $cabinet?->isActive() === true ? CabinetStatus::ACTIVE : CabinetStatus::PENDING,
                'specialization' => $this->specialties->display($specialty),
                'wilaya_code' => (int) $data['wilaya'],
            ];

            if ($cabinet === null) {
                $cabinet = Cabinet::query()->create($cabinetData);
            } else {
                $cabinet->forceFill($cabinetData)->save();
            }

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

            // Listed from day one: discovery only surfaces active cabinets,
            // so the doctor reaches the patient app the moment the cabinet is
            // activated, with no second switch for an admin to forget.
            $this->listing->setListed($cabinet, true);

            // The examination and medication catalogues are per-cabinet, so a
            // new cabinet would otherwise open with an empty prescription list
            // and nothing to order — with no way to populate either from
            // inside the application.
            $this->catalogue->provisionFor($cabinet);

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
     *
     * The guard is read from the User model, not from `auth.defaults.guard`:
     * authenticating a request as a token holder rewrites that config key for
     * the rest of the process, so an admin provisioning a clinic over the API
     * would otherwise mint a second "Doctor" role under the `sanctum` guard
     * and then fail to assign it.
     */
    private function administratorRole(): RoleContract
    {
        $roleClass = app(PermissionRegistrar::class)->getRoleClass();

        return $roleClass::findOrCreate(
            RoleName::ADMINISTRATOR->value,
            Guard::getDefaultName(User::class),
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
