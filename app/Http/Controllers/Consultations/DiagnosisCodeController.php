<?php

namespace App\Http\Controllers\Consultations;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Services\Clinical\Cim10Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiagnosisCodeController extends Controller
{
    public function search(Request $request, Cim10Catalog $catalog): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $query = (string) ($validated['q'] ?? '');

        return response()->json(['results' => $catalog->search($query)]);
    }

    /**
     * Replaces the consultation's coded diagnoses (max 8, catalogue codes
     * only).
     */
    public function sync(Request $request, Consultation $consultation, Cim10Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'codes' => ['present', 'array', 'max:8'],
            'codes.*' => ['string', 'max:12'],
        ]);

        $entries = [];

        foreach (array_unique($data['codes']) as $code) {
            $entry = $catalog->find((string) $code);

            if ($entry === null) {
                throw ValidationException::withMessages(['codes' => 'Code CIM-10 inconnu : '.$code]);
            }

            $entries[] = $entry;
        }

        DB::transaction(function () use ($consultation, $entries): void {
            ConsultationDiagnosis::query()->where('consultation_id', $consultation->getKey())->delete();

            foreach ($entries as $entry) {
                ConsultationDiagnosis::query()->create([
                    'consultation_id' => $consultation->getKey(),
                    'patient_id' => $consultation->patient_id,
                    'code' => $entry['code'],
                    'label' => $entry['label'],
                ]);
            }
        });

        AuditLog::record('consultation.diagnoses_coded', $consultation, [
            'codes' => array_column($entries, 'code'),
        ]);

        return back();
    }
}
