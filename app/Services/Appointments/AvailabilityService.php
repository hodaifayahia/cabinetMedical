<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Computes appointment availability for the single cabinet doctor.
 *
 * A day is bookable only when its month has been explicitly opened, the weekday
 * is a configured working day, it is not covered by an all-day closure
 * (e.g. Eid), it is not in the past, and at least one slot is still free.
 */
class AvailabilityService
{
    /** @var Collection<int, Collection<int, DoctorSchedule>>|null */
    private ?Collection $scheduleCache = null;

    public function __construct(private readonly DoctorProfile $doctor) {}

    public static function forCurrentDoctor(): ?self
    {
        $doctor = DoctorProfile::current();

        return $doctor instanceof DoctorProfile ? new self($doctor) : null;
    }

    public function doctor(): DoctorProfile
    {
        return $this->doctor;
    }

    /**
     * Build a per-day availability overview for a calendar month.
     *
     * @return array{year: int, month: int, is_open_month: bool, days: list<array<string, mixed>>}
     */
    public function monthOverview(int $year, int $month): array
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $daysInMonth = (int) $first->daysInMonth;
        $today = CarbonImmutable::now()->startOfDay();

        $isOpenMonth = $this->isOpenMonth($year, $month);
        $schedules = $this->activeSchedules();
        $timeOff = $this->timeOffBetween($first, $first->addMonth());
        $appointments = $this->appointmentsBetween($first, $first->addMonth());

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
                $availableCount = collect($this->buildSlots($date, $daySchedules, $timeOff, $appointments))
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
     * Resolve the concrete bookable slots for a single date.
     *
     * @return array{date: string, reason: string|null, slots: list<array<string, mixed>>}
     */
    public function slotsForDate(CarbonImmutable $date): array
    {
        $date = $date->startOfDay();

        if (! $this->isOpenMonth((int) $date->year, (int) $date->month)) {
            return ['date' => $date->toDateString(), 'reason' => 'month_closed', 'slots' => []];
        }

        $daySchedules = $this->activeSchedules()->get((int) $date->dayOfWeekIso, new Collection);

        if ($daySchedules->isEmpty()) {
            return ['date' => $date->toDateString(), 'reason' => 'not_working_day', 'slots' => []];
        }

        $timeOff = $this->timeOffBetween($date, $date->addDay());

        if ($this->isDayOff($date, $timeOff)) {
            return ['date' => $date->toDateString(), 'reason' => 'day_off', 'slots' => []];
        }

        $appointments = $this->appointmentsBetween($date, $date->addDay());

        return [
            'date' => $date->toDateString(),
            'reason' => null,
            'slots' => $this->buildSlots($date, $daySchedules, $timeOff, $appointments),
        ];
    }

    /**
     * Confirm a slot is still free right before persisting a booking.
     */
    public function isSlotBookable(CarbonImmutable $startsAt): bool
    {
        return collect($this->slotsForDate($startsAt)['slots'])
            ->contains(fn (array $slot): bool => $slot['starts_at'] === $startsAt->toIso8601String() && $slot['available'] === true);
    }

    /**
     * @param  Collection<int, DoctorSchedule>  $daySchedules
     * @param  Collection<int, DoctorTimeOff>  $timeOff
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function buildSlots(CarbonImmutable $date, Collection $daySchedules, Collection $timeOff, Collection $appointments): array
    {
        $now = CarbonImmutable::now();
        $slots = [];

        foreach ($daySchedules as $schedule) {
            $duration = $this->slotDuration($schedule);
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

    private function isOpenMonth(int $year, int $month): bool
    {
        return $this->doctor->openMonths()
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_open', true)
            ->exists();
    }

    /**
     * @return Collection<int, Collection<int, DoctorSchedule>>
     */
    private function activeSchedules(): Collection
    {
        if ($this->scheduleCache !== null) {
            return $this->scheduleCache;
        }

        /** @var Collection<int, Collection<int, DoctorSchedule>> $schedules */
        $schedules = $this->doctor->schedules()
            ->where('is_active', true)
            ->get()
            ->groupBy(static fn (DoctorSchedule $schedule): int => $schedule->day_of_week->value);

        return $this->scheduleCache = $schedules;
    }

    /**
     * @return Collection<int, DoctorTimeOff>
     */
    private function timeOffBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->doctor->timeOff()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Appointment::query()
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get();
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

    private function slotDuration(DoctorSchedule $schedule): int
    {
        $duration = $schedule->slot_duration
            ?? $this->doctor->consultation_duration
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
