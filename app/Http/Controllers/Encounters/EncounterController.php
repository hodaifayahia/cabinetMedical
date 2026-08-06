<?php

namespace App\Http\Controllers\Encounters;

use App\Actions\Encounters\CreateAmendmentAction;
use App\Actions\Encounters\CreateEncounterAction;
use App\Actions\Encounters\SaveEncounterDraftAction;
use App\Actions\Encounters\SignEncounterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Encounters\CreateAmendmentRequest;
use App\Http\Requests\Encounters\StoreEncounterRequest;
use App\Http\Requests\Encounters\UpdateEncounterRequest;
use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EncounterController extends Controller
{
    public function index(Patient $patient): Response
    {
        $this->authorize('viewAny', Encounter::class);

        $encounters = $patient->encounters()
            ->with('provider:id,name', 'signedBy:id,name')
            ->orderByDesc('occurred_at')
            ->paginate(15);

        return Inertia::render('encounters/Index', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ],
            'encounters' => $encounters->through(function (Encounter $encounter) {
                return [
                    'id' => $encounter->id,
                    'status' => $encounter->status->value,
                    'occurred_at' => $encounter->occurred_at?->toDateString(),
                    'started_at' => $encounter->started_at?->toDateTimeString(),
                    'signed_at' => $encounter->signed_at?->toDateTimeString(),
                    'provider' => $encounter->provider ? [
                        'id' => $encounter->provider->id,
                        'name' => $encounter->provider->name,
                    ] : null,
                    'signed_by' => $encounter->signedBy ? [
                        'id' => $encounter->signedBy->id,
                        'name' => $encounter->signedBy->name,
                    ] : null,
                ];
            }),
        ]);
    }

    public function create(Patient $patient): Response
    {
        $this->authorize('create', Encounter::class);

        return Inertia::render('encounters/Create', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ],
        ]);
    }

    public function store(Patient $patient, StoreEncounterRequest $request): RedirectResponse
    {
        $this->authorize('create', Encounter::class);

        $encounter = app(CreateEncounterAction::class)->handle(
            $patient,
            auth()->user(),
            $request->validated()
        );

        return redirect()->route('app.patients.encounters.edit', [$patient, $encounter])
            ->with('toast', ['type' => 'success', 'message' => 'Encounter created. Start recording notes.']);
    }

    public function show(Patient $patient, Encounter $encounter): Response
    {
        $this->authorize('view', $encounter);

        $encounterData = $this->transformEncounter($encounter);

        return Inertia::render('encounters/Show', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ],
            'encounter' => $encounterData,
            'amendments' => $encounter->amendments()
                ->with('provider:id,name', 'signedBy:id,name')
                ->get()
                ->map(fn (Encounter $e) => $this->transformEncounter($e)),
        ]);
    }

    public function edit(Patient $patient, Encounter $encounter): Response
    {
        $this->authorize('update', $encounter);

        $encounterData = $this->transformEncounter($encounter);

        return Inertia::render('encounters/Edit', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ],
            'encounter' => $encounterData,
        ]);
    }

    public function update(Patient $patient, Encounter $encounter, UpdateEncounterRequest $request): RedirectResponse
    {
        $this->authorize('update', $encounter);

        $data = $request->validated();
        $lockVersion = (int) $data['lock_version'];
        unset($data['lock_version']);

        $sections = [
            'reason_for_visit' => $data['reason_for_visit'] ?? '',
            'clinical_examination' => $data['clinical_examination'] ?? '',
            'diagnosis_assessment' => $data['diagnosis_assessment'] ?? '',
            'treatment_plan' => $data['treatment_plan'] ?? '',
        ];

        try {
            app(SaveEncounterDraftAction::class)->handle(
                $encounter,
                auth()->user(),
                $sections,
                $lockVersion
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lock_version' => $e->getMessage()]);
        }

        return back()
            ->with('toast', ['type' => 'success', 'message' => 'Encounter notes saved.']);
    }

    public function sign(Patient $patient, Encounter $encounter): RedirectResponse
    {
        $this->authorize('sign', $encounter);

        try {
            app(SignEncounterAction::class)->handle($encounter, auth()->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['encounter' => $e->getMessage()]);
        }

        return redirect()->route('app.patients.encounters.show', [$patient, $encounter])
            ->with('toast', ['type' => 'success', 'message' => 'Encounter signed and locked.']);
    }

    public function createAmendment(Patient $patient, Encounter $encounter): Response
    {
        $this->authorize('amend', $encounter);

        return Inertia::render('encounters/CreateAmendment', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ],
            'originalEncounter' => $this->transformEncounter($encounter),
        ]);
    }

    public function storeAmendment(Patient $patient, Encounter $encounter, CreateAmendmentRequest $request): RedirectResponse
    {
        $this->authorize('amend', $encounter);

        try {
            $data = $request->validated();
            $reason = $data['amendment_reason'];
            unset($data['amendment_reason']);

            $amendment = app(CreateAmendmentAction::class)->handle(
                $encounter,
                auth()->user(),
                $reason,
                array_filter($data)
            );

            return redirect()->route('app.patients.encounters.edit', [$patient, $amendment])
                ->with('toast', ['type' => 'success', 'message' => 'Amendment created. Review and sign when ready.']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['amendment_reason' => $e->getMessage()]);
        }
    }

    /**
     * Transform an Encounter model to API-ready array.
     *
     * @return array<string, mixed>
     */
    private function transformEncounter(Encounter $encounter): array
    {
        $notes = $encounter->notes()
            ->where('revision_number', $encounter->revision_number)
            ->get()
            ->keyBy('section');

        return [
            'id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'status' => $encounter->status->value,
            'occurred_at' => $encounter->occurred_at?->toDateString(),
            'started_at' => $encounter->started_at?->toDateTimeString(),
            'signed_at' => $encounter->signed_at?->toDateTimeString(),
            'revision_number' => $encounter->revision_number,
            'lock_version' => $encounter->lock_version,
            'provider' => $encounter->provider ? [
                'id' => $encounter->provider->id,
                'name' => $encounter->provider->name,
            ] : null,
            'signed_by' => $encounter->signedBy ? [
                'id' => $encounter->signedBy->id,
                'name' => $encounter->signedBy->name,
            ] : null,
            'content_hash' => $encounter->content_hash,
            'notes' => [
                'reason_for_visit' => $notes->get('reason_for_visit')->content_text ?? '',
                'clinical_examination' => $notes->get('clinical_examination')->content_text ?? '',
                'diagnosis_assessment' => $notes->get('diagnosis_assessment')->content_text ?? '',
                'treatment_plan' => $notes->get('treatment_plan')->content_text ?? '',
            ],
            'amends_encounter_id' => $encounter->amends_encounter_id,
            'amendment_reason' => $encounter->amendment_reason,
        ];
    }
}
