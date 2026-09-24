<?php

namespace App\Http\Controllers\Appointments;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CabinetSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full-screen board for the waiting-room TV: who is being seen, who is
 * next. Patients appear as « Prénom I. » with their arrival number only,
 * never their full name or reason for visit.
 */
class WaitingRoomController extends Controller
{
    public function __invoke(): Response
    {
        $today = CarbonImmutable::now()->toDateString();

        $appointments = Appointment::query()
            ->whereDate('appointment_date', $today)
            ->with('patient:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get();

        // Tickets follow arrival order across the whole day.
        $arrived = $appointments
            ->filter(static fn (Appointment $appointment): bool => $appointment->checked_in_at !== null)
            ->sortBy(static fn (Appointment $appointment): int => $appointment->checked_in_at?->getTimestamp() ?? 0)
            ->values();
        $tickets = $arrived->mapWithKeys(static fn (Appointment $appointment, int $index): array => [$appointment->getKey() => $index + 1]);

        $row = static fn (Appointment $appointment): array => [
            'ticket' => $tickets->get($appointment->getKey()),
            'name' => self::displayName($appointment),
            'time' => $appointment->starts_at?->format('H:i'),
        ];

        return Inertia::render('waiting-room/Display', [
            'clinic' => CabinetSetting::current()->name,
            'board' => [
                'current' => $this->withStatus($arrived, AppointmentStatus::IN_PROGRESS)->map($row)->values()->all(),
                'waiting' => $this->withStatus($arrived, AppointmentStatus::CHECKED_IN)->map($row)->values()->all(),
                'upcoming' => $appointments
                    ->filter(static fn (Appointment $appointment): bool => in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)
                        && ($appointment->starts_at === null || $appointment->starts_at->greaterThanOrEqualTo(now()->subMinutes(30))))
                    ->take(4)
                    ->map($row)
                    ->values()
                    ->all(),
                'done' => $this->withStatus($arrived, AppointmentStatus::COMPLETED)->count(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, Appointment>
     */
    private function withStatus(Collection $appointments, AppointmentStatus $status): Collection
    {
        return $appointments->filter(static fn (Appointment $appointment): bool => $appointment->status === $status);
    }

    private static function displayName(Appointment $appointment): string
    {
        $patient = $appointment->patient;
        $first = trim((string) $patient->first_name);
        $initial = mb_strtoupper(mb_substr(trim((string) $patient->last_name), 0, 1));

        return trim($first.($initial !== '' ? ' '.$initial.'.' : ''));
    }
}
