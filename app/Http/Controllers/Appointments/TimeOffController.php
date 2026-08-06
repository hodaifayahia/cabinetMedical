<?php

namespace App\Http\Controllers\Appointments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\StoreTimeOffRequest;
use App\Models\DoctorProfile;
use App\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TimeOffController extends Controller
{
    /**
     * Register an all-day closure (e.g. a public holiday such as Eid).
     */
    public function store(StoreTimeOffRequest $request): RedirectResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            throw ValidationException::withMessages([
                'doctor' => 'No active doctor is configured for the cabinet.',
            ]);
        }

        $data = $request->validated();

        // Store the end boundary exclusively (midnight after the last day off)
        // so a single-day closure still blocks that whole calendar day.
        $doctor->timeOff()->create([
            'starts_at' => CarbonImmutable::parse($data['starts_at'])->startOfDay(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'])->startOfDay()->addDay(),
            'is_all_day' => true,
            'reason' => $data['reason'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Day off added.')]);

        return to_route('app.appointments.configure');
    }

    /**
     * Remove a closure.
     */
    public function destroy(DoctorTimeOff $timeOff): RedirectResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile || $timeOff->doctor_id !== $doctor->id) {
            abort(404);
        }

        $timeOff->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Day off removed.')]);

        return to_route('app.appointments.configure');
    }
}
