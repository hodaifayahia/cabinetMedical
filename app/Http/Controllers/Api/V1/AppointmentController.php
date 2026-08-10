<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointments\CreateAppointmentAction;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAppointmentRequest;
use App\Http\Requests\Appointments\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\Appointments\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    /**
     * Paginated list of the cabinet's appointments with optional filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'patient_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $appointments = Appointment::query()
            ->with('patient')
            ->when(isset($validated['from']), fn (Builder $q) => $q->whereDate('appointment_date', '>=', CarbonImmutable::parse($validated['from'])->toDateString()))
            ->when(isset($validated['to']), fn (Builder $q) => $q->whereDate('appointment_date', '<=', CarbonImmutable::parse($validated['to'])->toDateString()))
            ->when(isset($validated['patient_id']), fn (Builder $q) => $q->where('patient_id', $validated['patient_id']))
            ->when(isset($validated['status']) && AppointmentStatus::tryFrom($validated['status']) !== null, fn (Builder $q) => $q->where('status', $validated['status']))
            ->orderBy('starts_at')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment->load('patient'));
    }

    /**
     * Book an appointment. Reuses the availability check and creation action
     * that back the web booking dialog so the business rules never fork.
     */
    public function store(StoreAppointmentRequest $request, CreateAppointmentAction $action): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        /** @var User $user */
        $user = $request->user();
        $startsAt = CarbonImmutable::parse((string) $request->string('starts_at'));
        $idempotency = $this->idempotencyContext($request, $startsAt);

        if ($idempotency !== null) {
            $existing = Appointment::query()
                ->withTrashed()
                ->where('mobile_idempotency_key_hash', $idempotency['key_hash'])
                ->first();

            if ($existing !== null) {
                if (! hash_equals(
                    (string) $existing->mobile_idempotency_fingerprint,
                    $idempotency['fingerprint'],
                )) {
                    return response()->json([
                        'message' => "Cette cle d'idempotence a deja ete utilisee avec une autre demande.",
                        'reason' => 'idempotency_key_reused',
                    ], 409);
                }

                return (new AppointmentResource($existing->load('patient')))
                    ->response()
                    ->header('Idempotency-Replayed', 'true');
            }
        }

        $availability = AvailabilityService::forCurrentDoctor();

        if (! $availability instanceof AvailabilityService) {
            throw ValidationException::withMessages([
                'doctor' => "Aucun médecin actif n'est configuré pour ce cabinet.",
            ]);
        }

        $slot = collect($availability->slotsForDate($startsAt)['slots'])
            ->first(static fn (array $slot): bool => $slot['starts_at'] === $startsAt->toIso8601String());

        if ($slot === null || $slot['available'] !== true) {
            throw ValidationException::withMessages([
                'starts_at' => "Ce créneau n'est plus disponible. Veuillez en choisir un autre.",
            ]);
        }

        $patient = Patient::query()->findOrFail($request->integer('patient_id'));

        $appointment = $action->handle($patient, $user, [
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($slot['ends_at']),
            'reason' => $request->input('reason'),
            'reception_notes' => $request->input('reception_notes'),
            'prestation' => $request->input('prestation'),
            'status' => $request->input('status'),
            'mobile_idempotency_key_hash' => $idempotency['key_hash'] ?? null,
            'mobile_idempotency_fingerprint' => $idempotency['fingerprint'] ?? null,
        ]);

        return (new AppointmentResource($appointment->load('patient')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update mutable fields and/or perform a status transition, applying the
     * same guard rails as the web confirm/check-in/cancel endpoints.
     */
    public function update(UpdateAppointmentRequest $request, Appointment $appointment): AppointmentResource|JsonResponse
    {
        $this->authorize('update', $appointment);

        $data = $request->validated();
        $expectedVersion = $this->expectedVersion($request, $data['expected_version'] ?? null);

        if ($expectedVersion !== null && $expectedVersion !== (int) $appointment->sync_version) {
            return $this->versionConflict($appointment);
        }

        $attributes = [];

        foreach (['reason', 'reception_notes', 'prestation'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $data[$field] ?? null;
            }
        }

        if (isset($data['status'])) {
            $target = AppointmentStatus::from($data['status']);
            $attributes = array_merge($attributes, $this->statusTransition($request, $appointment, $target, $data));
        }

        $appointment->update($attributes);

        return new AppointmentResource($appointment->fresh()->load('patient'));
    }

    /**
     * Delete an appointment record.
     */
    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);

        $expectedVersion = $this->expectedVersion($request);

        if ($expectedVersion !== null && $expectedVersion !== (int) $appointment->sync_version) {
            return $this->versionConflict($appointment);
        }

        $appointment->delete();

        return response()->json(['message' => 'Rendez-vous supprimé.']);
    }

    /**
     * Compute the attribute changes for a requested status transition,
     * validating that it is legal from the current state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function statusTransition(Request $request, Appointment $appointment, AppointmentStatus $target, array $data): array
    {
        return match ($target) {
            AppointmentStatus::CONFIRMED => $this->guard(
                $appointment->status === AppointmentStatus::SCHEDULED,
                'Seuls les rendez-vous programmés peuvent être confirmés.',
            ) + [
                'status' => AppointmentStatus::CONFIRMED,
                'confirmed_at' => CarbonImmutable::now(),
            ],
            AppointmentStatus::CHECKED_IN => $this->guard(
                in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true),
                'Ce rendez-vous ne peut pas être marqué comme arrivé.',
            ) + [
                'status' => AppointmentStatus::CHECKED_IN,
                'confirmed_at' => $appointment->confirmed_at ?? CarbonImmutable::now(),
                'checked_in_at' => CarbonImmutable::now(),
            ],
            AppointmentStatus::CANCELLED => $this->guard(
                ! in_array($appointment->status, [AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW], true),
                'Ce rendez-vous ne peut plus être annulé.',
            ) + [
                'status' => AppointmentStatus::CANCELLED,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $request->user()?->id,
                'cancellation_reason' => $data['cancellation_reason'] ?? null,
            ],
            default => throw ValidationException::withMessages([
                'status' => "Cette transition de statut n'est pas prise en charge par l'API.",
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function guard(bool $condition, string $message): array
    {
        if (! $condition) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return [];
    }

    /**
     * @return array{key_hash: string, fingerprint: string}|null
     */
    private function idempotencyContext(StoreAppointmentRequest $request, CarbonImmutable $startsAt): ?array
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->input('client_request_id', '')));

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw ValidationException::withMessages([
                'client_request_id' => "La cle d'idempotence doit contenir entre 8 et 200 caracteres.",
            ]);
        }

        $fingerprintPayload = [
            'patient_id' => $request->integer('patient_id'),
            'starts_at' => $startsAt->utc()->toIso8601String(),
            'reason' => $request->input('reason'),
            'reception_notes' => $request->input('reception_notes'),
            'prestation' => $request->input('prestation'),
            'status' => $request->input('status', AppointmentStatus::SCHEDULED->value),
        ];
        $encoded = json_encode(
            $fingerprintPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            'key_hash' => hash('sha256', 'appointment|'.$key),
            'fingerprint' => hash('sha256', $encoded),
        ];
    }

    private function expectedVersion(Request $request, mixed $bodyVersion = null): ?int
    {
        if ($bodyVersion !== null) {
            return (int) $bodyVersion;
        }

        $header = trim((string) $request->header('If-Match', ''));

        if ($header === '') {
            return null;
        }

        if (preg_match('/^(?:W\/)?"?(\d+)"?$/', $header, $matches) !== 1) {
            throw ValidationException::withMessages([
                'expected_version' => "L'en-tete If-Match doit contenir une version numerique.",
            ]);
        }

        return (int) $matches[1];
    }

    private function versionConflict(Appointment $appointment): JsonResponse
    {
        return response()->json([
            'message' => 'Le rendez-vous a ete modifie sur un autre appareil. Rechargez-le avant de reessayer.',
            'reason' => 'sync_version_conflict',
            'public_id' => $appointment->public_id,
            'current_version' => (int) $appointment->sync_version,
        ], 409);
    }
}
