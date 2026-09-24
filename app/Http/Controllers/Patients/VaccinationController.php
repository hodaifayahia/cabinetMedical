<?php

namespace App\Http\Controllers\Patients;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientVaccination;
use App\Services\Clinical\VaccinationSchedule;
use App\Services\DocumentBrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;

class VaccinationController extends Controller
{
    public function store(Request $request, Patient $patient, VaccinationSchedule $schedule): RedirectResponse
    {
        $data = $request->validate([
            'vaccine' => ['required_without:schedule_key', 'nullable', 'string', 'max:120'],
            'dose' => ['nullable', 'string', 'max:60'],
            'schedule_key' => ['nullable', 'string', 'max:40'],
            'given_on' => ['required', 'date', 'before_or_equal:today'],
            'lot' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $slotLabel = null;

        if (filled($data['schedule_key'] ?? null)) {
            $slotLabel = $schedule->slotLabel((string) $data['schedule_key']);

            if ($slotLabel === null) {
                throw ValidationException::withMessages(['schedule_key' => 'Dose du calendrier inconnue.']);
            }
        }

        $vaccination = PatientVaccination::query()->create([
            'patient_id' => $patient->getKey(),
            'vaccine' => filled($data['vaccine'] ?? null) ? trim((string) $data['vaccine']) : (string) $slotLabel,
            'dose' => filled($data['dose'] ?? null) ? trim((string) $data['dose']) : null,
            'schedule_key' => $data['schedule_key'] ?? null,
            'given_on' => $data['given_on'],
            'lot' => filled($data['lot'] ?? null) ? trim((string) $data['lot']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'created_by' => $request->user()?->getKey(),
        ]);

        AuditLog::record('patient.vaccination_recorded', $patient, ['vaccination_id' => $vaccination->getKey()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vaccin enregistré dans le carnet.']);

        return back();
    }

    public function destroy(PatientVaccination $vaccination): RedirectResponse
    {
        AuditLog::record('patient.vaccination_deleted', $vaccination->patient, [
            'vaccine' => $vaccination->vaccine,
            'given_on' => $vaccination->given_on->toDateString(),
        ]);
        $vaccination->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vaccination supprimée.']);

        return back();
    }

    public function print(Patient $patient, VaccinationSchedule $schedule, DocumentBrandingService $branding): View
    {
        return view('patients.vaccination-card', [
            'branding' => $branding->renderingIdentity(),
            'patient' => $patient,
            'card' => $schedule->forPatient($patient),
        ]);
    }
}
