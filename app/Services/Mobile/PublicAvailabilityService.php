<?php

namespace App\Services\Mobile;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Public availability for an explicit doctor, mirroring the slot logic of
 * App\Services\Appointments\AvailabilityService.
 *
 * The staff service leans on the BelongsToCabinet global scope to confine its
 * queries to the caller's cabinet. Mobile callers are unauthenticated or
 * null-cabinet patient accounts — for whom that scope is inert — and a staff
 * token viewing another cabinet's calendar would inject its own cabinet
 * filter. Every query here therefore bypasses the tenant scope and carries an
 * explicit doctor/cabinet constraint instead. Payload shapes are kept
 * identical to the staff service so both calendars stay interchangeable.
 */
class PublicAvailabilityService
{
    /** @var array<int, Collection<int, Collection<int, DoctorSchedule>>> */
    private array $scheduleCache = [];

    /**
     * Build a per-day availability overview for a calendar month.
     *
     * @return array{year: int, month: int, is_open_month: bool, days: list<array<string, mixed>>}
     */
    public function monthForDoctor(DoctorProfile $doctor, int $year, int $month): array
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $daysInMonth = (int) $first->daysInMonth;
        $today = CarbonImmutable::now()->startOfDay();

        $isOpenMonth = $this->isOpenMonth($doctor, $year, $month);
        $schedules = $this->activeSchedules($doctor);
        $timeOff = $this->timeOffBetween($doctor, $first, $first->addMonth());
        $appointments = $this->appointmentsBetween($doctor, $first, $first->addMonth());

        $days = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = CarbonImmutable::create($year, $month, $day)->startOfDay();
            $weekday = (int) $date->dayOfWeekIso;
            $daySchedules = $schedules->get($weekday, new Collection);

            $isWorkingDay = $daySchedules->isNotEmpty();
            $isDayOff = $this->isDayOff($date, $timeOff);
            $isPast = $date->lessThan($today);

            $availableCount = 0;

            if ($isOpenMonth && $isWorkingDay && ! $isDayOff && ! $isPast) {
                $availableCount = collect($this->buildSlots($doctor, $date, $daySchedules, $timeOff, $appointments))
                    ->where('available', true)
                    ->count();
            }

            $days[] = [
                'date' => $date->toDateString(),
                'day' => $day,
                'weekday' => $weekday,
                'is_open_month' => $isOpenMonth,
                'is_working_day' => $isWorkingDay,
                'is_day_off' => $isDayOff,
                'is_past' => $isPast,
                'available_count' => $availableCount,
                'bookable' => $isOpenMonth && $isWorkingDay && ! $isDayOff && ! $isPast && $availableCount > 0,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'is_open_month' => $isOpenMonth,
            'days' => $days,
        ];
    }

    /**
     * Resolve the bookable slots for a single Y-m-d date. Same shape as the
     * staff day payload minus its staff-only appointments array.
     *
     * @return array{date: string, reason: string|null, slots: list<array<string, mixed>>}
     */
    public function dayForDoctor(DoctorProfile $doctor, string $date): array
    {
        return $this->slotsForDate($doctor, CarbonImmutable::parse($date));
    }

    /**
     * Confirm a slot is free right before persisting a booking. An existing
     * appointment may be ignored so a reschedule does not collide with its
     * own current block.
     */
    public function isSlotAvailable(DoctorProfile $doctor, CarbonImmutable $startsAt, ?Appointment $ignore = null): bool
    {
        return collect($this->slotsForDate($doctor, $startsAt, $ignore)['slots'])
            ->contains(static fn (array $slot): bool => $slot['starts_at'] === $startsAt->toIso8601String() && $slot['available'] === true);
    }

    /**
     * Effective slot duration (minutes) for the working range containing the
     * given start time: schedule row → doctor profile → clinic default.
     */
    public function slotDurationFor(DoctorProfile $doctor, CarbonImmutable $startsAt): int
    {
        $date = $startsAt->startOfDay();

        /** @var DoctorSchedule|null $schedule */
        $schedule = $this->activeSchedules($doctor)
            ->get((int) $startsAt->dayOfWeekIso, new Collection)
            ->first(function (DoctorSchedule $schedule) use ($date, $startsAt): bool {
                $windowStart = $date->setTimeFromTimeString($this->timeString($schedule->starts_at));
                $windowEnd = $date->setTimeFromTimeString($this->timeString($schedule->ends_at));

                return $startsAt->greaterThanOrEqualTo($windowStart) && $startsAt->lessThan($windowEnd);
            });

        return $this->slotDuration($doctor, $schedule);
    }

    /**
     * @return array{date: string, reason: string|null, slots: list<array<string, mixed>>}
     */
    private function slotsForDate(DoctorProfile $doctor, CarbonImmutable $date, ?Appointment $ignore = null): array
    {
        $date = $date->startOfDay();

        if (! $this->isOpenMonth($doctor, (int) $date->year, (int) $date->month)) {
            return ['date' => $date->toDateString(), 'reason' => 'month_closed', 'slots' => []];
        }

        $daySchedules = $this->activeSchedules($doctor)->get((int) $date->dayOfWeekIso, new Collection);

        if ($daySchedules->isEmpty()) {
            return ['date' => $date->toDateString(), 'reason' => 'not_working_day', 'slots' => []];
        }

        $timeOff = $this->timeOffBetween($doctor, $date, $date->addDay());

        if ($this->isDayOff($date, $timeOff)) {
            return ['date' => $date->toDateString(), 'reason' => 'day_off', 'slots' => []];
        }

        $appointments = $this->appointmentsBetween($doctor, $date, $date->addDay(), $ignore);

        return [
            'date' => $date->toDateString(),
            'reason' => null,
            'slots' => $this->buildSlots($doctor, $date, $daySchedules, $timeOff, $appointments),
        ];
    }

    /**
     * @param  Collection<int, DoctorSchedule>  $daySchedules
     * @param  Collection<int, DoctorTimeOff>  $timeOff
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function buildSlots(DoctorProfile $doctor, CarbonImmutable $date, Collection $daySchedules, Collection $timeOff, Collection $appointments): array
    {
        $now = CarbonImmutable::now();
        $slots = [];

        foreach ($daySchedules as $schedule) {
            $duration = $this->slotDuration($doctor, $schedule);
            $windowEnd = $date->setTimeFromTimeString($this->timeString($schedule->ends_at));
            $cursor = $date->setTimeFromTimeString($this->timeString($schedule->starts_at));

            while (true) {
                $slotEnd = $cursor->addMinutes($duration);

                if ($slotEnd->greaterThan($windowEnd)) {
                    break;
                }

                $isPast = $cursor->lessThanOrEqualTo($now);
                $blockedByClosure = $this->overlaps($cursor, $slotEnd, $timeOff);
                $isBooked = $this->overlaps($cursor, $slotEnd, $appointments);

                $slots[] = [
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $slotEnd->toIso8601String(),
                    'label' => $cursor->format('H:i'),
                    'end_label' => $slotEnd->format('H:i'),
                    'available' => ! $isPast && ! $blockedByClosure && ! $isBooked,
                    'reason' => match (true) {
                        $isBooked => 'booked',
                        $blockedByClosure => 'time_off',
                        $isPast => 'past',
                        default => null,
                    },
                ];

                $cursor = $slotEnd;
            }
        }

        usort($slots, static fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));

        return $slots;
    }

    private function isOpenMonth(DoctorProfile $doctor, int $year, int $month): bool
    {
        return DoctorOpenMonth::withoutCabinetScope()
            ->where('doctor_id', $doctor->getKey())
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_open', true)
            ->exists();
    }

    /**
     * @return Collection<int, Collection<int, DoctorSchedule>>
     */
    private function activeSchedules(DoctorProfile $doctor): Collection
    {
        return $this->scheduleCache[$doctor->getKey()] ??= DoctorSchedule::withoutCabinetScope()
            ->where('doctor_id', $doctor->getKey())
            ->where('is_active', true)
            ->get()
            ->toBase()
            ->groupBy(static fn (DoctorSchedule $schedule): int => $schedule->day_of_week->value);
    }

    /**
     * @return Collection<int, DoctorTimeOff>
     */
    private function timeOffBetween(DoctorProfile $doctor, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DoctorTimeOff::withoutCabinetScope()
            ->where('doctor_id', $doctor->getKey())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->toBase();
    }

    /**
     * Blocking appointments of the doctor's cabinet. Appointments carry no
     * doctor id — a cabinet hosts a single doctor — so the cabinet is the
     * explicit tenant constraint here.
     *
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(DoctorProfile $doctor, CarbonImmutable $start, CarbonImmutable $end, ?Appointment $ignore = null): Collection
    {
        $query = Appointment::withoutCabinetScope()
            ->where('cabinet_id', $doctor->getAttribute('cabinet_id'))
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        return $query->get()->toBase();
    }

    /**
     * @param  Collection<int, DoctorTimeOff>  $timeOff
     */
    private function isDayOff(CarbonImmutable $date, Collection $timeOff): bool
    {
        $dayStart = $date->startOfDay();
        $dayEnd = $dayStart->addDay();

        return $timeOff->contains(static fn (DoctorTimeOff $off): bool => $off->is_all_day
            && $off->starts_at->lessThan($dayEnd)
            && $off->ends_at->greaterThan($dayStart));
    }

    /**
     * @template TPeriod of DoctorTimeOff|Appointment
     *
     * @param  Collection<int, TPeriod>  $periods
     */
    private function overlaps(CarbonImmutable $start, CarbonImmutable $end, Collection $periods): bool
    {
        return $periods->contains(static fn (DoctorTimeOff|Appointment $period): bool => $period->starts_at->lessThan($end)
            && $period->ends_at->greaterThan($start));
    }

    private function slotDuration(DoctorProfile $doctor, ?DoctorSchedule $schedule): int
    {
        $duration = $schedule->slot_duration
            ?? $doctor->consultation_duration
            ?? (int) config('clinic.appointments.default_duration', 30);

        return max(1, (int) $duration);
    }

    private function timeString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
