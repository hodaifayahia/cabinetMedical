<?php

namespace App\Http\Controllers\Patients;

use App\Actions\Patients\CreatePatientAction;
use App\Actions\Patients\UpdatePatientAction;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patients\StorePatientRequest;
use App\Http\Requests\Patients\UpdatePatientRequest;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\EcgRecord;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Clinical\PatientSafety;
use App\Services\Clinical\VaccinationSchedule;
use App\Services\Patients\DuplicatePatientFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    /** Appointment statuses that are not upcoming visits. */
    private const CLOSED_APPOINTMENT_STATUSES = ['cancelled', 'completed', 'no_show'];

    /**
     * Display a searchable, paginated list of patients.
     */
    public function index(Request $request, DuplicatePatientFinder $duplicates): Response
    {
        $this->authorize('viewAny', Patient::class);

        $search = trim((string) $request->string('search'));

        $patients = Patient::query()
            ->search($search)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(12)
            ->withQueryString();

        $ids = array_map(static fn (Patient $patient): int => (int) $patient->getKey(), $patients->items());
        $visits = $duplicates->visitStats($ids);
        $nextAppointments = Appointment::query()
            ->whereIn('patient_id', $ids)
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', self::CLOSED_APPOINTMENT_STATUSES)
            ->selectRaw('patient_id, min(starts_at) as next_at')
            ->groupBy('patient_id')
            ->pluck('next_at', 'patient_id');
        $alerts = PatientAlert::query()
            ->whereIn('patient_id', $ids)
            ->where('is_active', true)
            ->selectRaw('patient_id, count(*) as total')
            ->groupBy('patient_id')
            ->pluck('total', 'patient_id');

        $patients->through(static fn (Patient $patient): array => [
            'id' => $patient->id,
            'patient_number' => $patient->patient_number,
            'full_name' => $patient->full_name,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'gender' => $patient->gender?->value,
            'phone' => $patient->phone,
            'city' => $patient->city,
            'created_at' => $patient->created_at?->toISOString(),
            'visits_count' => $visits[$patient->id]['count'] ?? 0,
            'last_visit_at' => $visits[$patient->id]['last'] ?? null,
            'next_appointment_at' => $nextAppointments->get($patient->id),
            'alerts_count' => (int) ($alerts->get($patient->id) ?? 0),
        ]);

        $user = $request->user();

        return Inertia::render('patients/Index', [
            'patients' => $patients,
            'filters' => ['search' => $search],
            'genders' => $this->genderOptions(),
            'bloodGroups' => $this->bloodGroupOptions(),
            'stats' => [
                'total' => Patient::query()->count(),
                'new_this_month' => Patient::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'seen_this_month' => Consultation::query()
                    ->where('consulted_at', '>=', now()->startOfMonth())
                    ->distinct()
                    ->count('patient_id'),
                'duplicates' => $user?->can('patients.delete') ? $duplicates->count() : null,
            ],
        ]);
    }

    /**
     * Show the form for creating a new patient.
     */
    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        return Inertia::render('patients/Create', [
            'genders' => $this->genderOptions(),
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Store a newly created patient.
     */
    public function store(StorePatientRequest $request, CreatePatientAction $action): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        /** @var User $user */
        $user = $request->user();

        $patient = $action->handle($request->validated(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient created.')]);

        return to_route('app.patients.show', $patient);
    }

    /**
     * Display a single patient's dossier.
     */
    public function show(Request $request, Patient $patient, PatientSafety $safety, VaccinationSchedule $vaccinations): Response
    {
        $this->authorize('view', $patient);

        return Inertia::render('patients/Show', [
            'patient' => $this->transform($patient),
            'safety' => $safety->summary($patient),
            'canEditSafety' => $request->user()?->can('patients.update') ?? false,
            'vaccinations' => $vaccinations->forPatient($patient),
            'overview' => $this->overview($patient),
            'canMerge' => $request->user()?->can('patients.delete') ?? false,
        ]);
    }

    /**
     * Show the form for editing an existing patient.
     */
    public function edit(Patient $patient): Response
    {
        $this->authorize('update', $patient);

        return Inertia::render('patients/Edit', [
            'patient' => $this->transform($patient),
            'genders' => $this->genderOptions(),
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Update an existing patient.
     */
    public function update(UpdatePatientRequest $request, Patient $patient, UpdatePatientAction $action): RedirectResponse
    {
        $this->authorize('update', $patient);

        $action->handle($patient, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient updated.')]);

        return to_route('app.patients.show', $patient);
    }

    /**
     * Return a single patient's editable detail as JSON (booking modal).
     */
    public function showJson(Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        return response()->json(['patient' => $this->transform($patient)]);
    }

    /**
     * Create a patient from the booking modal and return a lightweight summary.
     */
    public function storeJson(StorePatientRequest $request, CreatePatientAction $action): JsonResponse
    {
        $this->authorize('create', Patient::class);

        /** @var User $user */
        $user = $request->user();

        $patient = $action->handle($request->validated(), $user);

        return response()->json(['patient' => $this->summary($patient)], 201);
    }

    /**
     * Update a patient from the booking modal and return a lightweight summary.
     */
    public function updateJson(UpdatePatientRequest $request, Patient $patient, UpdatePatientAction $action): JsonResponse
    {
        $this->authorize('update', $patient);

        $action->handle($patient, $request->validated());

        return response()->json(['patient' => $this->summary($patient->refresh())]);
    }

    /**
     * The dossier at a glance: activity counts, the next visit, recent
     * history, and the dossiers merged into this one.
     *
     * @return array<string, mixed>
     */
    private function overview(Patient $patient): array
    {
        $consultations = Consultation::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('consulted_at')
            ->limit(6)
            ->get(['id', 'consulted_at', 'motif', 'diagnostic', 'status']);

        $next = Appointment::query()
            ->where('patient_id', $patient->getKey())
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', self::CLOSED_APPOINTMENT_STATUSES)
            ->orderBy('starts_at')
            ->first(['id', 'starts_at', 'reason', 'status']);

        $plain = static fn (?string $value): ?string => $value === null || trim(strip_tags($value)) === ''
            ? null
            : Str::limit(trim(html_entity_decode(strip_tags($value))), 140);

        return [
            'counts' => [
                'consultations' => Consultation::query()->where('patient_id', $patient->getKey())->count(),
                'prescriptions' => Prescription::query()->where('patient_id', $patient->getKey())->count(),
                'documents' => Document::query()->where('patient_id', $patient->getKey())->count(),
                'ecgs' => EcgRecord::query()->where('patient_id', $patient->getKey())->count(),
                'appointments' => Appointment::query()->where('patient_id', $patient->getKey())->count(),
            ],
            'next_appointment' => $next === null ? null : [
                'starts_at' => $next->starts_at?->toISOString(),
                'reason' => $next->reason,
            ],
            'recent_consultations' => $consultations->map(fn (Consultation $consultation): array => [
                'id' => $consultation->id,
                'consulted_at' => $consultation->consulted_at?->toISOString(),
                'motif' => $plain($consultation->motif),
                'diagnostic' => $plain($consultation->diagnostic),
                'status' => $consultation->status,
            ])->all(),
            'merged' => Patient::onlyTrashed()
                ->where('merged_into_id', $patient->getKey())
                ->orderByDesc('merged_at')
                ->get(['id', 'patient_number', 'first_name', 'last_name', 'merged_at'])
                ->map(fn (Patient $merged): array => [
                    'patient_number' => $merged->patient_number,
                    'full_name' => $merged->full_name,
                    'merged_at' => $merged->merged_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array{id: int, full_name: string, patient_number: string}
     */
    private function summary(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'full_name' => $patient->full_name,
            'patient_number' => $patient->patient_number,
        ];
    }

    /**
     * Build the full patient payload for detail and edit screens.
     *
     * @return array<string, mixed>
     */
    private function transform(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'full_name' => $patient->full_name,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'gender' => $patient->gender?->value,
            'phone' => $patient->phone,
            'secondary_phone' => $patient->secondary_phone,
            'email' => $patient->email,
            'address' => $patient->address,
            'city' => $patient->city,
            'emergency_contact_name' => $patient->emergency_contact_name,
            'emergency_contact_phone' => $patient->emergency_contact_phone,
            'blood_group' => $patient->blood_group?->value,
            'notes' => $patient->notes,
            'created_at' => $patient->created_at?->toISOString(),
            'updated_at' => $patient->updated_at?->toISOString(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function genderOptions(): array
    {
        return array_map(
            static fn (Gender $gender): array => [
                'value' => $gender->value,
                'label' => $gender->label(),
            ],
            Gender::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function bloodGroupOptions(): array
    {
        return array_map(
            static fn (BloodGroup $group): array => [
                'value' => $group->value,
                'label' => $group->value,
            ],
            BloodGroup::cases(),
        );
    }
}
