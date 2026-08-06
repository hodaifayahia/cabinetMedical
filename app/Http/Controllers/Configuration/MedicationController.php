<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\StoreMedicationRequest;
use App\Http\Requests\Configuration\UpdateMedicationRequest;
use App\Models\AuditLog;
use App\Models\Medication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MedicationController extends Controller
{
    /**
     * Display the searchable, paginated medication catalogue.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $medications = Medication::query()
            ->search($search)
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Medication $medication): array => [
                'id' => $medication->id,
                'name' => $medication->name,
                'dci' => $medication->dci,
                'form' => $medication->form,
                'dosage' => $medication->dosage,
                'notes' => $medication->notes,
                'is_active' => $medication->is_active,
            ]);

        return Inertia::render('configuration/Medications', [
            'medications' => $medications,
            'filters' => ['search' => $search],
            'forms' => $this->existingForms(),
        ]);
    }

    /**
     * Distinct, non-empty medication forms already stored, for the form dropdown.
     *
     * @return list<string>
     */
    private function existingForms(): array
    {
        $forms = Medication::query()
            ->whereNotNull('form')
            ->where('form', '!=', '')
            ->distinct()
            ->orderBy('form')
            ->pluck('form')
            ->all();

        return array_values(array_map(
            static fn (mixed $form): string => (string) $form,
            $forms,
        ));
    }

    /**
     * Store a new medication.
     */
    public function store(StoreMedicationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $medication = Medication::query()->create($request->validated());
            AuditLog::record('configuration.medication_created', $medication, [
                'keys' => array_keys($request->validated()),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Médicament ajouté.']);

        return back();
    }

    /**
     * Update an existing medication.
     */
    public function update(UpdateMedicationRequest $request, Medication $medication): RedirectResponse
    {
        DB::transaction(function () use ($request, $medication): void {
            $medication->update($request->validated());
            AuditLog::record('configuration.medication_updated', $medication, [
                'keys' => array_keys($request->validated()),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Médicament mis à jour.']);

        return back();
    }

    /**
     * Delete a medication.
     */
    public function destroy(Medication $medication): RedirectResponse
    {
        DB::transaction(function () use ($medication): void {
            AuditLog::record('configuration.medication_removed', $medication);
            $medication->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Médicament supprimé.']);

        return back();
    }
}
