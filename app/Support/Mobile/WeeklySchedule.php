<?php

namespace App\Support\Mobile;

use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use DateTimeInterface;

/**
 * Formats a doctor's active schedule rows into the mobile working-hours
 * shape: one entry per ISO weekday (1..7, always all seven), each carrying
 * its ordered time ranges. Shared by the clinic public detail, the clinic
 * profile management screen, and the schedule editor response.
 */
class WeeklySchedule
{
    /**
     * @return list<array{weekday: int, is_closed: bool, ranges: list<array{starts_at: string, ends_at: string, period: string, slot_duration: int}>}>
     */
    public static function forDoctor(?DoctorProfile $doctor): array
    {
        $byWeekday = $doctor?->schedules()
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->get()
            ->groupBy(static fn (DoctorSchedule $row): int => $row->day_of_week->value);

        $days = [];

        foreach (range(1, 7) as $weekday) {
            $ranges = array_values(
                ($byWeekday?->get($weekday) ?? collect())
                    ->map(static function (DoctorSchedule $row) use ($doctor): array {
                        $startsAt = self::time($row->starts_at);

                        return [
                            'starts_at' => $startsAt,
                            'ends_at' => self::time($row->ends_at),
                            'period' => $startsAt < '12:00' ? 'morning' : 'evening',
                            'slot_duration' => self::slotDuration($row, $doctor),
                        ];
                    })
                    ->all(),
            );

            $days[] = [
                'weekday' => $weekday,
                'is_closed' => $ranges === [],
                'ranges' => $ranges,
            ];
        }

        return $days;
    }

    private static function slotDuration(DoctorSchedule $row, ?DoctorProfile $doctor): int
    {
        // getAttribute: the column is nullable at runtime even though the
        // model docblock advertises int.
        $duration = $row->getAttribute('slot_duration');

        if ($duration === null && $doctor !== null) {
            $duration = $doctor->consultation_duration;
        }

        if ($duration === null) {
            $duration = (int) config('clinic.appointments.default_duration', 30);
        }

        return max(1, (int) $duration);
    }

    private static function time(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
