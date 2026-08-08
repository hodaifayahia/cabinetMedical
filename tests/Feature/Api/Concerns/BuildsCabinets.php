<?php

namespace Tests\Feature\Api\Concerns;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\Cabinet;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Shared factory helpers for the API feature tests: builds active cabinets with
 * an approved administrator owner and, optionally, a bookable doctor.
 */
trait BuildsCabinets
{
    /**
     * @return array{0: Cabinet, 1: User}
     */
    protected function activeCabinetWithOwner(string $email, CabinetStatus $status = CabinetStatus::ACTIVE): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => $status,
            'activated_at' => $status === CabinetStatus::ACTIVE ? now() : null,
        ]);

        $owner = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $owner->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [$cabinet, $owner];
    }

    /**
     * Configure a bookable single doctor for the given cabinet owner: an active
     * profile plus a weekly schedule and an open month covering $date.
     */
    protected function configureBookableDoctor(User $owner, CarbonImmutable $date): DoctorProfile
    {
        $this->actingAs($owner);

        $doctor = DoctorProfile::factory()->for($owner, 'user')->create([
            'consultation_duration' => 30,
        ]);

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'day_of_week' => Weekday::from((int) $date->dayOfWeekIso),
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
            'slot_duration' => 30,
            'is_active' => true,
        ]);

        DoctorOpenMonth::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'year' => (int) $date->year,
            'month' => (int) $date->month,
            'is_open' => true,
        ]);

        return $doctor;
    }
}
