<?php

namespace App\Http\Controllers\Appointments;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientRecall;
use App\Services\Billing\FinanceReport;
use App\Services\Communication\PatientMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Relances » : who to remind today — tomorrow's appointments, recalls
 * that are due, unpaid balances — with one-click WhatsApp / SMS / call.
 */
class ReminderController extends Controller
{
    public function __construct(
        private readonly PatientMessages $messages,
    ) {}

    public function index(Request $request, FinanceReport $finance): Response
    {
        $validated = $request->validate(['date' => ['nullable', 'date']]);
        $now = CarbonImmutable::now();
        $date = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'])->startOfDay()
            : $now->addDay()->startOfDay();

        $appointments = Appointment::query()
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', [AppointmentStatus::SCHEDULED->value, AppointmentStatus::CONFIRMED->value])
            ->with('patient:id,first_name,last_name,patient_number,phone')
            ->orderBy('starts_at')
            ->get()
            ->map(function (Appointment $appointment) use ($date): array {
                $patient = $appointment->patient;
                $startsAt = $appointment->starts_at ?? $date;

                return [
                    'id' => (int) $appointment->getKey(),
                    'time' => $appointment->starts_at?->format('H:i'),
                    'patient_id' => $patient->getKey(),
                    'patient_name' => $patient->full_name,
                    'patient_number' => $patient->patient_number,
                    'phone' => $patient->phone,
                    'reason' => $appointment->prestation ?: $appointment->reason,
                    'status' => $appointment->status->value,
                    'reminded_at' => $appointment->getAttribute('reminded_at'),
                    'links' => $this->messages->links(
                        $patient->phone,
                        $this->messages->appointmentReminder($patient->full_name, $startsAt),
                    ),
                ];
            })
            ->values()
            ->all();

        $recalls = PatientRecall::query()
            ->where('status', PatientRecall::PENDING)
            ->whereDate('due_on', '<=', $now->addDays(30)->toDateString())
            ->with('patient:id,first_name,last_name,patient_number,phone')
            ->orderBy('due_on')
            ->get()
            ->map(fn (PatientRecall $recall): array => $this->recallPayload($recall, $now))
            ->values()
            ->all();

        $balances = [];

        if ($request->user()?->can('payments.view') ?? false) {
            $currency = AccountingSetting::current()->currency ?? 'DA';
            /** @var list<array<string, mixed>> $debtors */
            $debtors = $finance->receivables($now, 30)['debtors'];

            $balances = array_map(fn (array $debtor): array => [
                ...$debtor,
                'links' => $this->messages->links(
                    is_string($debtor['phone'] ?? null) ? $debtor['phone'] : null,
                    $this->messages->balanceReminder((string) $debtor['patient_name'], (float) $debtor['amount'], $currency),
                ),
            ], $debtors);
        }

        return Inertia::render('reminders/Index', [
            'date' => $date->toDateString(),
            'appointments' => $appointments,
            'recalls' => $recalls,
            'balances' => $balances,
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'canManage' => $request->user()?->can('appointments.update') ?? false,
        ]);
    }

    public function markAppointmentReminded(Appointment $appointment): RedirectResponse
    {
        $appointment->forceFill(['reminded_at' => now()])->save();
        AuditLog::record('appointment.reminded', $appointment);

        return back();
    }

    public function storeRecall(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'consultation_id' => ['nullable', 'integer'],
        ]);

        $consultationId = isset($data['consultation_id'])
            ? Consultation::query()
                ->whereKey($data['consultation_id'])
                ->where('patient_id', $patient->getKey())
                ->value('id')
            : null;

        $recall = PatientRecall::query()->create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $consultationId,
            'due_on' => $data['due_on'],
            'reason' => trim((string) $data['reason']),
            'status' => PatientRecall::PENDING,
            'created_by' => $request->user()?->getKey(),
        ]);

        AuditLog::record('patient.recall_created', $patient, ['recall_id' => $recall->getKey()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rappel programmé pour le '.$recall->due_on->format('d/m/Y').'.']);

        return back();
    }

    public function updateRecall(Request $request, PatientRecall $recall): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['contacted', 'done', 'cancelled'])],
        ]);

        $action = (string) $data['action'];

        $recall->update(match ($action) {
            'contacted' => ['contacted_at' => now()],
            'done' => ['status' => PatientRecall::DONE, 'completed_at' => now()],
            default => ['status' => PatientRecall::CANCELLED, 'completed_at' => now()],
        });

        AuditLog::record('patient.recall_'.$action, $recall->patient, ['recall_id' => $recall->getKey()]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public function recallPayload(PatientRecall $recall, CarbonImmutable $now): array
    {
        $patient = $recall->patient;
        $days = (int) $now->startOfDay()->diffInDays($recall->due_on->startOfDay(), false);

        return [
            'id' => $recall->public_id,
            'patient_id' => $patient->getKey(),
            'patient_name' => $patient->full_name,
            'patient_number' => $patient->patient_number,
            'phone' => $patient->phone,
            'due_on' => $recall->due_on->toDateString(),
            'days' => $days,
            'reason' => $recall->reason,
            'contacted_at' => $recall->contacted_at?->toIso8601String(),
            'links' => $this->messages->links(
                $patient->phone,
                $this->messages->recallReminder($patient->full_name, $recall->reason, $recall->due_on),
            ),
        ];
    }
}
