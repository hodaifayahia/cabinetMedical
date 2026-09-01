<?php

namespace Tests\Support;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;

/**
 * Shared builders for the mobile API feature tests. Callers must seed
 * RolesAndPermissionsSeeder in setUp() (see existing Api feature tests)
 * before using these helpers, so the Patient and Doctor roles exist.
 */
trait MobileTestHelpers
{
    /**
     * An active cabinet with a listed public profile and an active doctor:
     * the minimum a clinic needs to appear in mobile discovery.
     *
     * @return array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}
     */
    protected function makeListedClinic(): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.fake()->unique()->lastName(),
            'status' => CabinetStatus::ACTIVE,
            'wilaya_code' => 16,
            'activated_at' => now(),
        ]);

        $doctorUser = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $doctorUser->assignRole(RoleName::DOCTOR->value);
        $cabinet->forceFill(['owner_user_id' => $doctorUser->getKey()])->save();

        // No authenticated cabinet user here, so cabinet_id is explicit — the
        // BelongsToCabinet creating hook only fires for cabinet-bound actors.
        $doctor = DoctorProfile::factory()
            ->for($doctorUser, 'user')
            ->create([
                'cabinet_id' => $cabinet->getKey(),
                'is_active' => true,
            ]);

        CabinetPublicProfile::factory()->listed()->create([
            'cabinet_id' => $cabinet->getKey(),
        ]);

        return [
            'cabinet' => $cabinet,
            'doctor' => $doctor,
            'doctorUser' => $doctorUser,
        ];
    }

    /**
     * A platform superadmin: is_platform_admin, no cabinet, no tenant role.
     * Mirrors what `platform:provision-superadmin` writes, which is the only
     * supported way to create one outside the tests.
     */
    protected function makePlatformAdmin(): User
    {
        return User::factory()->create([
            'cabinet_id' => null,
            'is_platform_admin' => true,
            'approved_at' => now(),
        ]);
    }

    /**
     * A registered mobile patient: Patient role, no cabinet, unique Algerian
     * phone, and a demographic profile row.
     */
    protected function makePatientUser(): User
    {
        $user = User::factory()->create([
            'cabinet_id' => null,
            'phone' => '0'.fake()->randomElement(['5', '6', '7']).fake()->unique()->numerify('########'),
            'approved_at' => now(),
        ]);
        $user->assignRole(RoleName::PATIENT->value);

        PatientProfile::factory()->for($user)->create();

        return $user;
    }
}
