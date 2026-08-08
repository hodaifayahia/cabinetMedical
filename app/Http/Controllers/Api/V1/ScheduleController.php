<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /**
     * Return the cabinet's doctor schedules, time off and open months.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            return response()->json([
                'doctor' => null,
                'schedules' => [],
                'time_off' => [],
                'open_months' => [],
            ]);
        }

        $schedules = $doctor->schedules()->orderBy('day_of_week')->get()
            ->map(static fn (DoctorSchedule $s): array => [
                'id' => $s->id,
                'day_of_week' => $s->day_of_week?->value,
                'starts_at' => $s->starts_at,
                'ends_at' => $s->ends_at,
                'slot_duration' => $s->slot_duration,
                'is_active' => (bool) $s->is_active,
            ]);

        $timeOff = $doctor->timeOff()->orderBy('starts_at')->get()
            ->map(static fn (DoctorTimeOff $t): array => [
                'id' => $t->id,
                'starts_at' => $t->starts_at?->toIso8601String(),
                'ends_at' => $t->ends_at?->toIso8601String(),
                'is_all_day' => (bool) $t->is_all_day,
                'reason' => $t->reason,
            ]);

        $openMonths = $doctor->openMonths()->orderBy('year')->orderBy('month')->get()
            ->map(static fn (DoctorOpenMonth $m): array => [
                'id' => $m->id,
                'year' => $m->year,
                'month' => $m->month,
                'is_open' => (bool) $m->is_open,
                'note' => $m->note,
            ]);

        return response()->json([
            'doctor' => [
                'id' => $doctor->id,
                'doctor_name' => $doctor->doctor_name,
                'specialty' => $doctor->specialty,
                'consultation_duration' => $doctor->consultation_duration,
            ],
            'schedules' => $schedules,
            'time_off' => $timeOff,
            'open_months' => $openMonths,
        ]);
    }
}
