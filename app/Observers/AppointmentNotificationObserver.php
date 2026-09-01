<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\Mobile\AppointmentStatusChanged;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Turns appointment status changes into database notifications for the mobile
 * clients (booking patient and cabinet owner). Registered in
 * AppServiceProvider via Appointment::observe().
 *
 * Runs alongside the sync hooks declared in Appointment::booted() and never
 * mutates the appointment, so the sync_version bookkeeping is untouched.
 */
class AppointmentNotificationObserver
{
    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status') || $appointment->booked_by_user_id === null) {
            return;
        }

        try {
            $this->notifyStatusChange($appointment);
        } catch (Throwable $exception) {
            // A broken notification must never roll back the status change.
            report($exception);
        }
    }

    private function notifyStatusChange(Appointment $appointment): void
    {
        $actor = Auth::user();
        $actorIsBookingPatient = $actor instanceof User
            && (int) $actor->getKey() === (int) $appointment->booked_by_user_id;

        $cabinet = Cabinet::query()->find($appointment->cabinet_id);

        // The booking patient acted → tell the clinic; anyone (or anything)
        // else acted → tell the booking patient.
        $recipient = $actorIsBookingPatient
            ? $cabinet?->owner
            : $appointment->bookedBy;

        if (! $recipient instanceof User) {
            return;
        }

        $recipient->notify(new AppointmentStatusChanged(
            $appointment,
            changedByRole: $actor instanceof User ? $actor->mobileRole() : 'system',
            doctorName: $this->doctorName($appointment),
            clinicName: $cabinet?->name,
        ));
    }

    /**
     * The display name of the cabinet's active doctor. Explicitly unscoped:
     * the actor may be a patient account, for which the cabinet scope is inert.
     */
    private function doctorName(Appointment $appointment): ?string
    {
        $profile = DoctorProfile::withoutCabinetScope()
            ->with('user')
            ->where('cabinet_id', $appointment->cabinet_id)
            ->where('is_active', true)
            ->first();

        if ($profile === null) {
            return null;
        }

        return $profile->doctor_name ?? $profile->user?->name;
    }
}
