<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\AccountingSetting;
use App\Models\Act;
use App\Models\Appointment;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Support\MedicalSpecialtyCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MedicalSpecialtyCatalog $specialties,
    ) {}

    /**
     * Status swatches used across the appointment charts.
     */
    private const STATUS_COLORS = [
        'scheduled' => '#07545a',
        'confirmed' => '#08746f',
        'checked_in' => '#23927f',
        'in_progress' => '#4fbd92',
        'completed' => '#72e5b3',
        'cancelled' => '#64748b',
        'no_show' => '#94a3b8',
    ];

    private const STATUS_LABELS = [
        'scheduled' => 'Planifié',
        'confirmed' => 'Confirmé',
        'checked_in' => 'Arrivé',
        'in_progress' => 'En cours',
        'completed' => 'Terminé',
        'cancelled' => 'Annulé',
        'no_show' => 'Absent',
    ];

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::now()->startOfDay();
        $doctor = DoctorProfile::query()
            ->active()
            ->with('user:id,name')
            ->first();
        $cabinet = CabinetSetting::current();

        return Inertia::render('Dashboard', [
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'stats' => $this->stats($today),
            'revenueTrend' => $this->revenueTrend($today),
            'appointmentsByStatus' => $this->appointmentsByStatus(),
            'appointmentsTrend' => $this->appointmentsTrend($today),
            'topPrestations' => $this->topPrestations(),
            'recentPayments' => $this->recentPayments(),
            'profile' => [
                'welcome_name' => $request->user()?->name,
                'clinic_name' => $cabinet->name,
                'doctor_name' => $doctor?->user?->name,
                'specialty' => $this->specialties->display(
                    $doctor?->specialty,
                    $doctor?->specialty_code,
                ) ?: null,
                'professional_identifier' => $doctor?->professional_identifier,
                'prescriptions_total' => Prescription::query()->count(),
            ],
        ]);
    }

    /**
     * Headline KPI figures for the stat cards.
     *
     * @return array<string, float|int|null>
     */
    private function stats(CarbonImmutable $today): array
    {
        $thisMonth = $this->revenueBetween($today->startOfMonth(), $today->endOfMonth());
        $previousMonth = $today->startOfMonth()->subMonth();
        $lastMonth = $this->revenueBetween($previousMonth->startOfMonth(), $previousMonth->endOfMonth());

        $change = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : null;

        $appointmentsThisMonth = Appointment::query()
            ->whereBetween('appointment_date', [
                $today->startOfMonth()->toDateString(),
                $today->endOfMonth()->toDateString(),
            ])
            ->count();

        $prestationsTotal = ConsultationFee::query()->where('is_active', true)->count()
            + Act::query()->where('is_active', true)->count();

        return [
            'revenue_this_month' => $thisMonth / 100,
            'revenue_last_month' => $lastMonth / 100,
            'revenue_total' => $this->revenueBetween(null, null) / 100,
            'revenue_change' => $change,
            'appointments_total' => Appointment::query()->count(),
            'appointments_this_month' => $appointmentsThisMonth,
            'prestations_total' => $prestationsTotal,
            'patients_total' => Patient::query()->count(),
            'consultations_total' => Consultation::query()->count(),
        ];
    }

    /**
     * Sum actual cash collections (in minor units) within an optional window.
     * Legacy paid consultations without a ledger row remain represented.
     */
    private function revenueBetween(?CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        $ledgerMinor = (int) Payment::query()
            ->when($from !== null, static fn ($query) => $query->where('received_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('received_at', '<=', $to))
            ->sum('amount_minor');

        $legacyMinor = (int) Consultation::query()
            ->where('is_paid', true)
            ->whereDoesntHave('payments')
            ->when($from !== null, static fn ($query) => $query->where('consulted_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('consulted_at', '<=', $to))
            ->sum('payment_amount_minor');

        return $ledgerMinor + $legacyMinor;
    }

    /**
     * Monthly revenue for the trailing six months (major units).
     *
     * @return list<array{label: string, value: float}>
     */
    private function revenueTrend(CarbonImmutable $today): array
    {
        $start = $today->startOfMonth()->subMonths(5);

        /** @var array<string, array{label: string, value: float}> $buckets */
        $buckets = [];
        for ($i = 0; $i < 6; $i++) {
            $month = $start->addMonths($i);
            $buckets[$month->format('Y-m')] = [
                'label' => $month->translatedFormat('M'),
                'value' => 0.0,
            ];
        }

        $rows = Payment::query()
            ->where('received_at', '>=', $start)
            ->get(['amount_minor', 'received_at'])
            ->map(fn (Payment $payment): array => [
                'amount_minor' => $payment->amount_minor,
                'received_at' => $payment->received_at,
            ])
            ->concat(
                Consultation::query()
                    ->where('is_paid', true)
                    ->whereDoesntHave('payments')
                    ->where('consulted_at', '>=', $start)
                    ->get(['payment_amount_minor', 'consulted_at'])
                    ->map(fn (Consultation $consultation): array => [
                        'amount_minor' => (int) ($consultation->payment_amount_minor ?? 0),
                        'received_at' => $consultation->consulted_at,
                    ]),
            );

        foreach ($rows as $row) {
            $receivedAt = $row['received_at'];
            $key = $receivedAt instanceof CarbonInterface
                ? $receivedAt->format('Y-m')
                : null;

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['value'] += (int) $row['amount_minor'] / 100;
            }
        }

        return array_values($buckets);
    }

    /**
     * Appointment counts grouped by every status, with display metadata.
     *
     * @return list<array{status: string, label: string, count: int, color: string}>
     */
    private function appointmentsByStatus(): array
    {
        $rows = Appointment::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        $result = [];
        foreach (AppointmentStatus::cases() as $case) {
            $result[] = [
                'status' => $case->value,
                'label' => self::STATUS_LABELS[$case->value],
                'count' => (int) ($rows[$case->value] ?? 0),
                'color' => self::STATUS_COLORS[$case->value],
            ];
        }

        return $result;
    }

    /**
     * Appointment volume for the trailing fourteen days.
     *
     * @return list<array{label: string, value: int}>
     */
    private function appointmentsTrend(CarbonImmutable $today): array
    {
        $start = $today->subDays(13);

        /** @var array<string, array{label: string, value: int}> $buckets */
        $buckets = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $start->addDays($i);
            $buckets[$day->toDateString()] = [
                'label' => $day->translatedFormat('j M'),
                'value' => 0,
            ];
        }

        $rows = Appointment::query()
            ->where('appointment_date', '>=', $start->toDateString())
            ->pluck('appointment_date');

        foreach ($rows as $date) {
            $key = $date instanceof CarbonImmutable ? $date->toDateString() : (string) $date;

            if (isset($buckets[$key])) {
                $buckets[$key]['value']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * Most frequently booked prestations.
     *
     * @return list<array{label: string, value: int}>
     */
    private function topPrestations(): array
    {
        $rows = Appointment::query()
            ->whereNotNull('prestation')
            ->where('prestation', '!=', '')
            ->groupBy('prestation')
            ->selectRaw('prestation, count(*) as aggregate')
            ->orderByDesc('aggregate')
            ->limit(6)
            ->pluck('aggregate', 'prestation');

        $result = [];
        foreach ($rows as $label => $count) {
            $result[] = ['label' => (string) $label, 'value' => (int) $count];
        }

        return $result;
    }

    /**
     * The latest cash collections for the "recent earnings" feed.
     *
     * @return list<array{id: int, patient_name: string, patient_number: string|null, amount: float, method: string|null, date_label: string|null}>
     */
    private function recentPayments(): array
    {
        $ledger = Payment::query()
            ->with('consultation.patient:id,first_name,last_name,patient_number')
            ->orderByDesc('received_at')
            ->limit(6)
            ->get()
            ->map(function (Payment $payment): array {
                $consultation = $payment->consultation;
                $patient = $consultation->patient;

                return [
                    'id' => (int) $consultation->id,
                    'patient_name' => $patient->full_name,
                    'patient_number' => $patient->patient_number,
                    'amount' => (float) ($payment->amount_minor / 100),
                    'method' => $payment->method,
                    'date_label' => $payment->received_at?->translatedFormat('j M Y'),
                    'sort_at' => $payment->received_at?->getTimestamp() ?? 0,
                ];
            });

        $legacy = Consultation::query()
            ->where('is_paid', true)
            ->whereDoesntHave('payments')
            ->with('patient:id,first_name,last_name,patient_number')
            ->orderByDesc('consulted_at')
            ->limit(6)
            ->get()
            ->map(function (Consultation $consultation): array {
                $patient = $consultation->patient;
                $consultedAt = $consultation->getAttribute('consulted_at');

                return [
                    'id' => (int) $consultation->id,
                    'patient_name' => $patient->full_name,
                    'patient_number' => $patient->patient_number,
                    'amount' => (float) ($consultation->payment_amount_minor / 100),
                    'method' => $consultation->payment_method,
                    'date_label' => $consultedAt instanceof CarbonInterface
                        ? $consultedAt->translatedFormat('j M Y')
                        : null,
                    'sort_at' => $consultedAt instanceof CarbonInterface
                        ? $consultedAt->getTimestamp()
                        : 0,
                ];
            });

        $rows = $ledger
            ->concat($legacy)
            ->sortByDesc('sort_at')
            ->take(6);

        /** @var list<array{id: int, patient_name: string, patient_number: string|null, amount: float, method: string|null, date_label: string|null}> $result */
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'patient_name' => $row['patient_name'],
                'patient_number' => $row['patient_number'],
                'amount' => $row['amount'],
                'method' => $row['method'],
                'date_label' => $row['date_label'],
            ];
        }

        return $result;
    }
}
