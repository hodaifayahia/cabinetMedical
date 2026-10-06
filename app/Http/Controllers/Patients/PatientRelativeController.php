<?php

namespace App\Http\Controllers\Patients;

use App\Actions\Patients\LinkPatientRelativeAction;
use App\Enums\PatientRelation;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientRelative;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Family links between dossiers: « Ali est le frère de Sara ». A relative's
 * allergies and chronic diseases then show on the patient's dossier and in
 * the consultation.
 */
class PatientRelativeController extends Controller
{
    /**
     * Dossiers of the cabinet that can be linked to this patient.
     */
    public function search(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $term = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen(str_replace(' ', '', $term)) < 2) {
            return response()->json(['patients' => []]);
        }

        $linked = PatientRelative::query()
            ->where('patient_id', $patient->getKey())
            ->pluck('relative_patient_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $now = now();

        $patients = Patient::query()
            ->matchingWords($term)
            ->whereKeyNot($patient->getKey())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'patient_number', 'phone', 'date_of_birth', 'gender']);

        return response()->json([
            'patients' => $patients->map(static fn (Patient $candidate): array => [
                'id' => $candidate->getKey(),
                'name' => $candidate->full_name,
                'number' => $candidate->patient_number,
                'phone' => $candidate->phone,
                'gender' => $candidate->gender?->value,
                'age' => $candidate->date_of_birth !== null ? (int) $candidate->date_of_birth->diffInYears($now) : null,
                'linked' => in_array((int) $candidate->getKey(), $linked, true),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, Patient $patient, LinkPatientRelativeAction $action): RedirectResponse
    {
        $this->authorize('update', $patient);

        $data = $request->validate([
            'relative_id' => ['required', 'integer', Rule::notIn([$patient->getKey()])],
            'relation' => ['required', 'string', Rule::enum(PatientRelation::class)],
        ], [
            'relative_id.not_in' => 'Un patient ne peut pas être son propre proche.',
        ]);

        // Resolved through the cabinet scope: another cabinet's dossier is
        // simply not found.
        $relative = Patient::query()->find((int) $data['relative_id']);

        if (! $relative instanceof Patient) {
            throw ValidationException::withMessages(['relative_id' => 'Ce patient est introuvable dans ce cabinet.']);
        }

        $this->authorize('update', $relative);

        /** @var User|null $user */
        $user = $request->user();

        try {
            $action->link($patient, $relative, PatientRelation::from($data['relation']), $user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['relative_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $relative->full_name.' ajouté(e) aux proches de '.$patient->full_name.'.',
        ]);

        return back();
    }

    public function destroy(Patient $patient, PatientRelative $relative, LinkPatientRelativeAction $action): RedirectResponse
    {
        $this->authorize('update', $patient);
        abort_unless((int) $relative->patient_id === (int) $patient->getKey(), 404);

        $other = Patient::withTrashed()->find($relative->relative_patient_id);

        if ($other instanceof Patient) {
            $action->unlink($patient, $other);
        } else {
            $relative->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lien familial retiré.']);

        return back();
    }
}
