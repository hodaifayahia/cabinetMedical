<?php

namespace App\Actions\Appointments;

use App\Models\DoctorProfile;
use Illuminate\Support\Facades\DB;

class SyncDoctorScheduleAction
{
    /**
     * Replace the doctor's weekly working hours with the supplied day set.
     *
     * @param  array<int, array{day_of_week: int, is_working: bool, starts_at: string, ends_at: string, slot_duration: int|null}>  $days
     */
    public function handle(DoctorProfile $doctor, array $days): void
    {
        DB::transaction(function () use ($doctor, $days): void {
            $doctor->schedules()->delete();

            foreach ($days as $day) {
                if (empty($day['is_working'])) {
                    continue;
                }

                $doctor->schedules()->create([
                    'day_of_week' => (int) $day['day_of_week'],
                    'starts_at' => $day['starts_at'],
                    'ends_at' => $day['ends_at'],
                    'slot_duration' => $day['slot_duration'] ?? null,
                    'is_active' => true,
                ]);
            }
        });
    }
}
