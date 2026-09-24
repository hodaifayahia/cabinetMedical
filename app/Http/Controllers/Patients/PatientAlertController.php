<?php

namespace App\Http\Controllers\Patients;

use App\Enums\PatientAlertType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manages a patient's safety list (allergies, chronic conditions,
 * long-term treatments).
 */
class PatientAlertController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PatientAlertType::class)],
            'label' => ['required', 'string', 'max:180'],
            'severity' => ['nullable', Rule::in(array_keys(PatientAlert::SEVERITIES))],
            'details' => ['nullable', 'string', 'max:500'],
            'since' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $alert = PatientAlert::query()->create([
            'patient_id' => $patient->getKey(),
            'type' => $data['type'],
            'label' => trim((string) $data['label']),
            'severity' => $data['type'] === PatientAlertType::ALLERGY->value ? ($data['severity'] ?? null) : null,
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'since' => $data['since'] ?? null,
            'created_by' => $request->user()?->getKey(),
        ]);

        AuditLog::record('patient.alert_added', $patient, [
            'type' => $alert->type->value,
            'alert_id' => $alert->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $alert->type->label().' ajoutée au dossier.']);

        return back();
    }

    /**
     * Stops an alert (e.g. treatment ended) without erasing its history.
     */
    public function deactivate(PatientAlert $alert): RedirectResponse
    {
        $alert->update(['is_active' => false]);

        AuditLog::record('patient.alert_deactivated', $alert->patient, [
            'type' => $alert->type->value,
            'alert_id' => $alert->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Élément retiré de la fiche de sécurité.']);

        return back();
    }

    public function destroy(PatientAlert $alert): RedirectResponse
    {
        AuditLog::record('patient.alert_deleted', $alert->patient, [
            'type' => $alert->type->value,
            'label' => $alert->label,
        ]);
        $alert->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Élément supprimé (saisie erronée).']);

        return back();
    }
}
