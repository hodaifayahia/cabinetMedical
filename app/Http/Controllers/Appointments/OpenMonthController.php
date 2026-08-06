<?php

namespace App\Http\Controllers\Appointments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\StoreOpenMonthRequest;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OpenMonthController extends Controller
{
    /**
     * Open a month so it starts accepting bookings.
     */
    public function store(StoreOpenMonthRequest $request): RedirectResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            throw ValidationException::withMessages([
                'doctor' => 'No active doctor is configured for the cabinet.',
            ]);
        }

        $data = $request->validated();

        $doctor->openMonths()->updateOrCreate(
            ['year' => (int) $data['year'], 'month' => (int) $data['month']],
            ['is_open' => true, 'note' => $data['note'] ?? null],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Month opened for booking.')]);

        return to_route('app.appointments.configure');
    }

    /**
     * Close a month (removes it from the booking calendar).
     */
    public function destroy(DoctorOpenMonth $openMonth): RedirectResponse
    {
        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile || $openMonth->doctor_id !== $doctor->id) {
            abort(404);
        }

        $openMonth->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Month closed.')]);

        return to_route('app.appointments.configure');
    }
}
