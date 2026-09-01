<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\StoreMobileTimeOffRequest;
use App\Http\Requests\Api\Mobile\UpdateMobileScheduleRequest;
use App\Http\Resources\Mobile\TimeOffResource;
use App\Models\DoctorProfile;
use App\Models\DoctorTimeOff;
use App\Support\Mobile\WeeklySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weekly-hours and time-off management from the staff mobile app. All
 * endpoints sit behind the appointments.configure permission middleware;
 * the doctor is always the caller's cabinet's single active profile.
 */
class StaffScheduleController extends Controller
{
    /**
     * Replace the doctor's whole weekly schedule. Multi-range aware: one row
     * is stored per range, so a day can hold e.g. a morning and an evening
     * session. Days missing from the payload become closed.
     */
    public function update(UpdateMobileScheduleRequest $request): JsonResponse
    {
        $doctor = $this->currentDoctor();

        /** @var array<int, array{day_of_week: int, ranges: array<int, array{starts_at: string, ends_at: string, slot_duration?: int|null}>}> $days */
        $days = $request->validated('days');

        DB::transaction(function () use ($doctor, $days): void {
            $doctor->schedules()->delete();

            foreach ($days as $day) {
                foreach ($day['ranges'] as $range) {
                    $doctor->schedules()->create([
                        'day_of_week' => (int) $day['day_of_week'],
                        'starts_at' => $range['starts_at'],
                        'ends_at' => $range['ends_at'],
                        'slot_duration' => $range['slot_duration'] ?? null,
                        'is_active' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'data' => ['working_hours' => WeeklySchedule::forDoctor($doctor)],
        ]);
    }

    /**
     * Register a closure. All-day closures store an exclusive end boundary
     * (midnight after the last day off) exactly like the web editor, so a
     * single-day closure still blocks that whole calendar day.
     */
    public function storeTimeOff(StoreMobileTimeOffRequest $request): JsonResponse
    {
        $doctor = $this->currentDoctor();
        $data = $request->validated();
        $isAllDay = $request->boolean('is_all_day', true);

        // Normalize a possible foreign offset (UTC-serializing clients) to
        // the app timezone before deriving day boundaries and storing into
        // the naive datetime columns.
        $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone(config('app.timezone'));
        $endsAt = CarbonImmutable::parse($data['ends_at'])->setTimezone(config('app.timezone'));

        $timeOff = $doctor->timeOff()->create([
            'starts_at' => $isAllDay ? $startsAt->startOfDay() : $startsAt,
            'ends_at' => $isAllDay ? $endsAt->startOfDay()->addDay() : $endsAt,
            'is_all_day' => $isAllDay,
            'reason' => $data['reason'] ?? null,
        ]);

        return (new TimeOffResource($timeOff))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a closure. The route binding is cabinet-scoped for staff, and
     * the row must belong to the cabinet's own doctor.
     */
    public function destroyTimeOff(Request $request, DoctorTimeOff $timeOff): JsonResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile || (int) $timeOff->doctor_id !== (int) $doctor->getKey()) {
            abort(404);
        }

        $timeOff->delete();

        return response()->json(['message' => 'Absence supprimée.']);
    }

    private function currentDoctor(): DoctorProfile
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            throw ValidationException::withMessages([
                'doctor' => "Aucun médecin actif n'est configuré pour ce cabinet.",
            ]);
        }

        return $doctor;
    }
}
