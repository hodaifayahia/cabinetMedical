<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

        $query = Patient::query();

        // Every word must match somewhere: « amina kaci » and « kaci amina »
        // both find Amina Kaci.
        foreach (array_slice($words, 0, 4) as $word) {
            $like = '%'.$word.'%';
            $digits = preg_replace('/\D+/', '', $word) ?? '';

            $query->where(static function (Builder $nested) use ($like, $digits): void {
                $nested->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('patient_number', 'like', $like)
                    ->orWhere('phone', 'like', $like);

                if (strlen($digits) >= 4) {
                    // « 0555123456 » also finds « 0555 12 34 56 ».
                    $nested->orWhereRaw("replace(replace(phone, ' ', ''), '-', '') like ?", ['%'.$digits.'%']);
                }
            });
        }

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
