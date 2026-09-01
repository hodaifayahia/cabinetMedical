<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\CancelPatientAppointmentRequest;
use App\Http\Requests\Api\Mobile\StorePatientAppointmentRequest;
use App\Http\Resources\Mobile\MobileAppointmentResource;
use App\Models\Appointment;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Mobile\PatientBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Appointments booked by the authenticated mobile patient. The caller has
 * cabinet_id = null so the BelongsToCabinet scope filters nothing: every
 * query below constrains booked_by_user_id explicitly and never relies on
 * the tenant scope.
 */
class PatientAppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'string', Rule::in(['upcoming', 'past'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $scope = $validated['scope'] ?? null;

        /** @var User $user */
        $user = $request->user();

        $appointments = Appointment::withoutCabinetScope()
            ->where('booked_by_user_id', $user->getKey())
            ->with(['patient', 'familyMember', 'cabinet'])
            ->when($scope === 'upcoming', fn (Builder $q) => $q
                ->where('starts_at', '>=', CarbonImmutable::now())
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->orderBy('starts_at'))
            ->when($scope === 'past', fn (Builder $q) => $q
                ->where(fn (Builder $inner) => $inner
                    ->where('starts_at', '<', CarbonImmutable::now())
                    ->orWhereNotIn('status', AppointmentStatus::blockingValues()))
                ->orderByDesc('starts_at'))
            ->when($scope === null, fn (Builder $q) => $q->orderByDesc('starts_at'))
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        $this->attachClinicContext($appointments->getCollection());

        return MobileAppointmentResource::collection($appointments);
    }

    public function store(StorePatientAppointmentRequest $request, PatientBookingService $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $doctor = $booking->resolveBookableDoctor($request->integer('doctor_id'));

        if ($doctor === null) {
            abort(404, "Ce médecin n'est pas ouvert à la réservation en ligne.");
        }

        $familyMember = null;

        if ($request->filled('family_member_id')) {
            $familyMember = FamilyMember::query()->find($request->integer('family_member_id'));

            if ($familyMember === null
                || (int) $familyMember->owner_user_id !== (int) $user->getKey()
                || ! $familyMember->isUsableForBooking()) {
                return response()->json([
                    'message' => 'Ce membre de la famille ne peut pas être utilisé pour cette réservation.',
                    'reason' => 'family_member_not_usable',
                ], 403);
            }
        }

        // Normalize to the app timezone: an ISO string carrying a foreign
        // offset (e.g. a client serializing in UTC) would otherwise shift the
        // whole availability grid AND be stored verbatim in the naive column,
        // bypassing the overlap check for the real local slot.
        $startsAt = CarbonImmutable::parse((string) $request->string('starts_at'))
            ->setTimezone(config('app.timezone'));

        $appointment = $booking->book($user, $doctor, $startsAt, $familyMember, $request->input('reason'));

        if ($appointment === null) {
            return response()->json([
                'message' => "Ce créneau n'est plus disponible. Veuillez en choisir un autre.",
                'reason' => 'slot_unavailable',
            ], 409);
        }

        $appointment->load(['patient', 'familyMember', 'cabinet']);
        $this->attachClinicContext(collect([$appointment]));

        return (new MobileAppointmentResource($appointment))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $publicId): MobileAppointmentResource
    {
        $appointment = $this->ownedAppointment($request, $publicId);

        $this->attachClinicContext(collect([$appointment]));

        return new MobileAppointmentResource($appointment);
    }

    /**
     * Cancel an appointment the patient booked, mirroring the staff cancel
     * transition guard rails plus the mobile cancellation cutoff.
     */
    public function cancel(CancelPatientAppointmentRequest $request, string $publicId): MobileAppointmentResource|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $appointment = $this->ownedAppointment($request, $publicId);

        if (! in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Ce rendez-vous ne peut plus être annulé.',
            ]);
        }

        $cutoffHours = (int) config('clinic.appointments.patient_cancel_cutoff_hours', 2);

        if ($appointment->starts_at === null
            || $appointment->starts_at->lessThanOrEqualTo(CarbonImmutable::now()->addHours($cutoffHours))) {
            return response()->json([
                'message' => "Le délai d'annulation est dépassé. Veuillez contacter le cabinet directement.",
                'reason' => 'cancel_cutoff_passed',
            ], 422);
        }

        $appointment->status = AppointmentStatus::CANCELLED;
        $appointment->cancelled_at = CarbonImmutable::now();
        $appointment->cancelled_by = $user->getKey();
        $appointment->cancellation_reason = $request->validated('cancellation_reason');
        $appointment->save();

        $this->attachClinicContext(collect([$appointment]));

        return new MobileAppointmentResource($appointment);
    }

    /**
     * Resolve an appointment by public id, constrained to the ones this
     * patient booked. Anything else is a 404 — never a 403 that would leak
     * the existence of another patient's appointment.
     */
    private function ownedAppointment(Request $request, string $publicId): Appointment
    {
        /** @var User $user */
        $user = $request->user();

        return Appointment::withoutCabinetScope()
            ->where('public_id', $publicId)
            ->where('booked_by_user_id', $user->getKey())
            ->with(['patient', 'familyMember', 'cabinet'])
            ->firstOrFail();
    }

    /**
     * Attach the clinic-side context (active doctor + public listing) the
     * mobile payload exposes. Explicit unscoped lookups keyed by cabinet_id:
     * appointments may span several cabinets for one patient account.
     *
     * @param  Collection<int, Appointment>  $appointments
     */
    private function attachClinicContext(Collection $appointments): void
    {
        $cabinetIds = $appointments->pluck('cabinet_id')->filter()->unique()->values()->all();

        if ($cabinetIds === []) {
            return;
        }

        $doctors = DoctorProfile::withoutCabinetScope()
            ->with('user')
            ->whereIn('cabinet_id', $cabinetIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('cabinet_id');

        $publicProfiles = CabinetPublicProfile::withoutCabinetScope()
            ->whereIn('cabinet_id', $cabinetIds)
            ->get()
            ->keyBy('cabinet_id');

        foreach ($appointments as $appointment) {
            $appointment->setRelation('mobileDoctor', $doctors->get($appointment->cabinet_id));
            $appointment->setRelation('mobilePublicProfile', $publicProfiles->get($appointment->cabinet_id));
        }
    }
}
