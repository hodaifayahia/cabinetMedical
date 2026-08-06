<?php

namespace App\Http\Controllers\Patients;

use App\Actions\Patients\CreatePatientAction;
use App\Actions\Patients\UpdatePatientAction;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patients\StorePatientRequest;
use App\Http\Requests\Patients\UpdatePatientRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    /**
     * Display a searchable, paginated list of patients.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Patient::class);

        $search = trim((string) $request->string('search'));

        $patients = Patient::query()
            ->search($search)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Patient $patient): array => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => $patient->full_name,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'gender' => $patient->gender?->value,
                'phone' => $patient->phone,
                'city' => $patient->city,
                'created_at' => $patient->created_at?->toISOString(),
            ]);

        return Inertia::render('patients/Index', [
            'patients' => $patients,
            'filters' => ['search' => $search],
            'genders' => $this->genderOptions(),
            'bloodGroups' => $this->bloodGroupOptions(),
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
    public function show(Patient $patient): Response
    {
        $this->authorize('view', $patient);

        return Inertia::render('patients/Show', [
            'patient' => $this->transform($patient),
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
