<?php

namespace App\Http\Controllers\Appointments;

use App\Actions\Appointments\SyncDoctorScheduleAction;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\UpdateDoctorScheduleRequest;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    /**
     * Show the doctor availability configuration screen.
     */
    public function edit(): Response
    {
        $doctor = DoctorProfile::current();

        return Inertia::render('appointments/Configure', [
            'hasDoctor' => $doctor !== null,
            'weekdays' => $this->weekdayOptions(),
            'schedule' => $this->schedulePayload($doctor),
            'openMonths' => $this->openMonthsPayload($doctor),
            'timeOff' => $this->timeOffPayload($doctor),
            'defaultDuration' => (int) config('clinic.appointments.default_duration', 30),
        ]);
    }

    /**
     * Persist the doctor's weekly working hours.
     */
    public function update(UpdateDoctorScheduleRequest $request, SyncDoctorScheduleAction $action): RedirectResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            throw ValidationException::withMessages([
                'doctor' => 'No active doctor is configured for the cabinet.',
            ]);
        }

        /** @var array<int, array{day_of_week: int, is_working: bool, starts_at: string, ends_at: string, slot_duration: int|null}> $days */
        $days = $request->validated()['days'];

        $action->handle($doctor, $days);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Working hours updated.')]);

        return to_route('app.appointments.configure');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function weekdayOptions(): array
    {
        return array_map(
            static fn (Weekday $day): array => ['value' => $day->value, 'label' => $day->label()],
            Weekday::cases(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schedulePayload(?DoctorProfile $doctor): array
    {
        /** @var Collection<int, DoctorSchedule> $existing */
        $existing = $doctor
            ? $doctor->schedules()->where('is_active', true)->get()->keyBy(
                static fn (DoctorSchedule $schedule): int => $schedule->day_of_week->value
            )
            : new Collection;

        return array_map(function (Weekday $day) use ($existing): array {
            $row = $existing->get($day->value);

            return [
                'day_of_week' => $day->value,
                'label' => $day->label(),
                'is_working' => $row !== null,
                'starts_at' => $row ? substr((string) $row->starts_at, 0, 5) : '09:00',
                'ends_at' => $row ? substr((string) $row->ends_at, 0, 5) : '17:00',
                'slot_duration' => $row?->slot_duration,
            ];
        }, Weekday::cases());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openMonthsPayload(?DoctorProfile $doctor): array
    {
        if (! $doctor instanceof DoctorProfile) {
            return [];
        }

        return $doctor->openMonths()
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(static fn (DoctorOpenMonth $month): array => [
                'id' => $month->id,
                'year' => $month->year,
                'month' => $month->month,
                'is_open' => $month->is_open,
                'note' => $month->note,
                'label' => CarbonImmutable::create($month->year, $month->month, 1)->format('F Y'),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeOffPayload(?DoctorProfile $doctor): array
    {
        if (! $doctor instanceof DoctorProfile) {
            return [];
        }

        return $doctor->timeOff()
            ->orderBy('starts_at')
            ->get()
            ->map(static fn (DoctorTimeOff $timeOff): array => [
                'id' => $timeOff->id,
                'starts_at' => $timeOff->starts_at->toDateString(),
                'ends_at' => $timeOff->ends_at->subDay()->toDateString(),
                'is_all_day' => $timeOff->is_all_day,
                'reason' => $timeOff->reason,
            ])
            ->all();
    }
}
