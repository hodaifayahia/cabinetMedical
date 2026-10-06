<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ctrl+K search: patients by name (in any order), file number or phone,
 * with their next appointment.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) ($validated['q'] ?? ''))) ?: []));

        if ($words === [] || mb_strlen(implode('', $words)) < 2) {
            return response()->json(['patients' => []]);
        }

        // Every word must match somewhere: « amina kaci » and « kaci amina »
        // both find Amina Kaci.
        $query = Patient::query()->matchingWords(implode(' ', $words));

        $patients = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'patient_number', 'phone', 'date_of_birth']);

        $now = CarbonImmutable::now();
        $next = Appointment::query()
            ->whereIn('patient_id', $patients->pluck('id'))
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->get(['patient_id', 'starts_at'])
            ->groupBy('patient_id')
            ->map(static fn ($rows) => $rows->first()?->starts_at?->format('d/m/Y H:i'));

        return response()->json([
            'patients' => $patients->map(static fn (Patient $patient): array => [
                'id' => $patient->getKey(),
                'name' => $patient->full_name,
                'number' => $patient->patient_number,
                'phone' => $patient->phone,
                'age' => $patient->date_of_birth !== null ? (int) $patient->date_of_birth->diffInYears($now) : null,
                'next_appointment' => $next->get($patient->getKey()),
            ])->values()->all(),
        ]);
    }
}
