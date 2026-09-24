<?php

namespace App\Http\Controllers\Patients;

use App\Actions\Patients\MergePatientsAction;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\DuplicatePatientFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Merging two dossiers of the same person: find the duplicates, compare them
 * side by side, then fold one into the other.
 */
class PatientMergeController extends Controller
{
    /** Row counts shown to the doctor before merging, by table. */
    private const TABLE_LABELS = [
        'consultations' => 'Consultations',
        'appointments' => 'Rendez-vous',
        'prescriptions' => 'Ordonnances',
        'documents' => 'Documents',
        'payments' => 'Paiements',
        'ecg_records' => 'ECG',
        'patient_measurements' => 'Mesures',
        'patient_alerts' => 'Alertes',
        'patient_vaccinations' => 'Vaccinations',
        'patient_recalls' => 'Rappels',
        'encounters' => 'Actes',
        'clinical_observations' => 'Observations',
        'consultation_diagnoses' => 'Diagnostics codés',
    ];

    private const FIELD_LABELS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'date_of_birth' => 'Date de naissance',
        'gender' => 'Sexe',
        'blood_group' => 'Groupe sanguin',
        'phone' => 'Téléphone',
        'secondary_phone' => 'Téléphone secondaire',
        'email' => 'E-mail',
        'address' => 'Adresse',
        'city' => 'Ville',
        'place_of_birth' => 'Lieu de naissance',
        'marital_status' => 'Situation familiale',
        'profession' => 'Profession',
        'smoking_status' => 'Tabagisme',
        'referred_by' => 'Adressé par',
        'emergency_contact_name' => 'Contact d’urgence',
        'emergency_contact_phone' => 'Téléphone d’urgence',
        'allergies' => 'Allergies',
        'antecedents_medical' => 'Antécédents médicaux',
        'antecedents_surgical' => 'Antécédents chirurgicaux',
        'antecedents_family' => 'Antécédents familiaux',
        'antecedents_gyneco' => 'Antécédents gynéco-obstétricaux',
        'antecedents_other' => 'Autres antécédents',
        'notes' => 'Notes',
    ];

    /**
     * Every group of likely duplicates in the cabinet.
     */
    public function duplicates(DuplicatePatientFinder $finder): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        return response()->json(['groups' => $finder->groups()]);
    }

    /**
     * Likely duplicates of one patient, or dossiers matching a search.
     */
    public function candidates(Request $request, Patient $patient, DuplicatePatientFinder $finder): JsonResponse
    {
        $this->authorize('update', $patient);

        return response()->json([
            'candidates' => $finder->candidatesFor($patient, (string) $request->string('search')),
        ]);
    }

    /**
     * Side-by-side comparison and what the merge would move.
     */
    public function preview(Patient $patient, Patient $duplicate, MergePatientsAction $merge, DuplicatePatientFinder $finder): JsonResponse
    {
        $this->authorize('update', $patient);
        $this->authorize('delete', $duplicate);

        $summaries = $finder->summaries([$patient->getKey(), $duplicate->getKey()]);

        $fields = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            $kept = $this->display($patient, $field);
            $other = $this->display($duplicate, $field);

            if ($kept === null && $other === null) {
                continue;
            }

            $clinical = in_array($field, MergePatientsAction::CLINICAL_TEXT_FIELDS, true);

            $fields[] = [
                'field' => $field,
                'label' => $label,
                'primary' => $kept,
                'duplicate' => $other,
                'clinical' => $clinical,
                'conflict' => ! $clinical && $kept !== null && $other !== null && $kept !== $other,
            ];
        }

        $moves = collect($merge->preview($duplicate))
            ->map(fn (int $count, string $table): array => [
                'table' => $table,
                'label' => self::TABLE_LABELS[$table] ?? ucfirst(str_replace('_', ' ', $table)),
                'count' => $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return response()->json([
            'primary' => $summaries[$patient->getKey()] ?? null,
            'duplicate' => $summaries[$duplicate->getKey()] ?? null,
            'fields' => $fields,
            'moves' => $moves,
        ]);
    }

    /**
     * Fold the duplicate into this patient.
     */
    public function store(Request $request, Patient $patient, MergePatientsAction $merge): RedirectResponse
    {
        $this->authorize('update', $patient);

        $validated = $request->validate([
            'duplicate_id' => ['required', 'integer', Rule::notIn([$patient->getKey()])],
            'choices' => ['nullable', 'array'],
            'choices.*' => ['string', Rule::in(['primary', 'duplicate'])],
        ]);

        // Resolved through the cabinet scope: another cabinet's dossier is a 404.
        $duplicate = Patient::query()->findOrFail((int) $validated['duplicate_id']);
        $this->authorize('delete', $duplicate);

        $choices = array_intersect_key($validated['choices'] ?? [], array_flip(MergePatientsAction::IDENTITY_FIELDS));

        /** @var User $user */
        $user = $request->user();

        try {
            $moved = $merge->handle($patient, $duplicate, $user, $choices);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['duplicate_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Dossiers fusionnés : '.array_sum($moved).' élément(s) rattaché(s) à '.$patient->full_name.'.',
        ]);

        return to_route('app.patients.show', $patient);
    }

    private function display(Patient $patient, string $field): ?string
    {
        $value = $patient->getAttribute($field);

        if ($value instanceof \BackedEnum) {
            $value = method_exists($value, 'label') ? $value->label() : $value->value;
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format('d/m/Y');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
