<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\DeclineAppointmentRequest;
use App\Http\Requests\Api\Mobile\RescheduleAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\Mobile\AppointmentStatusChanged;
use App\Services\Mobile\PublicAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff mobile agenda actions. Guarded by cabinet.active.api, so the caller
 * is always a cabinet member: the cabinet global scope narrows every query
 * and route binding to their own tenant. Status-change notifications to the
 * booking patient are emitted by AppointmentNotificationObserver.
 */
class StaffAppointmentController extends Controller
{
    /**
     * The cabinet's appointments for one day (default today). Reuses the
     * staff resource — staff may see reception notes.
     */
    public function today(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $date = $validated['date'] ?? CarbonImmutable::now()->toDateString();

        $appointments = Appointment::query()
            ->with('patient')
            ->whereDate('appointment_date', $date)
            ->orderBy('starts_at')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    /**
     * Cancel from the clinic side, with a mandatory reason relayed to the
     * booking patient by the notification observer.
     */
    public function decline(DeclineAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $this->authorize('cancel', $appointment);

        $this->guard(
            in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true),
            'Seuls les rendez-vous programmés ou confirmés peuvent être refusés.',
        );

        $appointment->update([
            'status' => AppointmentStatus::CANCELLED,
            'cancelled_at' => CarbonImmutable::now(),
            'cancelled_by' => $request->user()?->id,
            'cancellation_reason' => $request->string('reason')->toString(),
        ]);

        return new AppointmentResource($appointment->fresh()->load('patient'));
    }

    /**
     * Move the appointment to a new free slot, keeping its current status.
     * The appointment's own block is ignored by the availability check so it
     * can also be nudged within its current window.
     */
    public function reschedule(
        RescheduleAppointmentRequest $request,
        Appointment $appointment,
        PublicAvailabilityService $availability,
    ): AppointmentResource|JsonResponse {
        $this->authorize('update', $appointment);

        $this->guard(
            in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true),
            'Ce rendez-vous ne peut plus être reprogrammé.',
        );

        $doctor = DoctorProfile::current();

        if (! $doctor instanceof DoctorProfile) {
            throw ValidationException::withMessages([
                'doctor' => "Aucun médecin actif n'est configuré pour ce cabinet.",
            ]);
        }

        // Normalize a possible foreign offset (UTC-serializing clients) to the
        // app timezone before the grid check and the naive-column write.
        $startsAt = CarbonImmutable::parse((string) $request->string('starts_at'))
            ->setTimezone(config('app.timezone'));

        // The availability re-check and the move are serialized against every
        // other booking writer by locking the doctor row, exactly like
        // CreateAppointmentAction — a plain transaction alone would let two
        // concurrent check-then-write flows both see the slot as free.
        $conflict = DB::transaction(function () use ($appointment, $availability, $doctor, $startsAt): bool {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                DoctorProfile::withoutCabinetScope()
                    ->whereKey($doctor->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            if (! $availability->isSlotAvailable($doctor, $startsAt, $appointment)) {
                return true;
            }

            $appointment->update([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($availability->slotDurationFor($doctor, $startsAt)),
            ]);

            return false;
        });

        if ($conflict) {
            return response()->json([
                'message' => "Ce créneau n'est plus disponible. Veuillez en choisir un autre.",
                'reason' => 'slot_unavailable',
            ], 409);
        }

        $appointment = $appointment->fresh();

        // The observer only reacts to status changes; a reschedule keeps the
        // status, so the booking patient is told about the new time here.
        $this->notifyBookedBy($request, $appointment, $doctor);

        return new AppointmentResource($appointment->load('patient'));
    }

    /**
     * Record that the patient did not show up.
     */
    public function noShow(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->authorize('update', $appointment);

        $this->guard(
            in_array($appointment->status, [
                AppointmentStatus::SCHEDULED,
                AppointmentStatus::CONFIRMED,
                AppointmentStatus::CHECKED_IN,
            ], true),
            'Ce rendez-vous ne peut pas être marqué comme absence.',
        );

        $appointment->update(['status' => AppointmentStatus::NO_SHOW]);

        return new AppointmentResource($appointment->fresh()->load('patient'));
    }

    private function guard(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * Tell the booking patient the appointment moved. Best-effort: a broken
     * notification must never fail the reschedule itself.
     */
    private function notifyBookedBy(Request $request, Appointment $appointment, DoctorProfile $doctor): void
    {
        $bookedBy = $appointment->bookedBy;

        if (! $bookedBy instanceof User) {
            return;
        }

        try {
            /** @var User $actor */
            $actor = $request->user();

            $bookedBy->notify(new AppointmentStatusChanged(
                $appointment,
                changedByRole: $actor->mobileRole(),
                doctorName: $doctor->doctor_name ?? $doctor->user?->name,
                clinicName: $actor->cabinet?->name,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
