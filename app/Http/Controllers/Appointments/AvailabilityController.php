<?php

namespace App\Http\Controllers\Appointments;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Appointments\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /**
     * Return the per-day availability overview for a calendar month.
     */
    public function month(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $availability = AvailabilityService::forCurrentDoctor();

        if ($availability === null) {
            return response()->json([
                'year' => (int) $validated['year'],
                'month' => (int) $validated['month'],
                'is_open_month' => false,
                'days' => [],
            ]);
        }

        return response()->json(
            $availability->monthOverview((int) $validated['year'], (int) $validated['month'])
        );
    }

    /**
     * Return the slots and booked appointments for a single date.
     */
    public function day(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = CarbonImmutable::parse($validated['date'])->startOfDay();
        $availability = AvailabilityService::forCurrentDoctor();

        $payload = $availability?->slotsForDate($date) ?? [
            'date' => $date->toDateString(),
            'reason' => 'no_doctor',
            'slots' => [],
        ];

        return response()->json([
            'date' => $date->toDateString(),
            'reason' => $payload['reason'],
            'slots' => $payload['slots'],
            'appointments' => $this->appointmentsForDate($date),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function appointmentsForDate(CarbonImmutable $date): array
    {
        return Appointment::query()
            ->with('patient:id,first_name,last_name,patient_number')
            ->whereDate('appointment_date', $date->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(static fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'patient_name' => $appointment->patient?->full_name,
                'patient_number' => $appointment->patient?->patient_number,
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'ends_at' => $appointment->ends_at?->toIso8601String(),
                'label' => $appointment->starts_at?->format('H:i'),
                'status' => $appointment->status->value,
                'reason' => $appointment->reason,
            ])
            ->all();
    }
}
