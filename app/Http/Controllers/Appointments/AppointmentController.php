<?php

namespace App\Http\Controllers\Appointments;

use App\Actions\Appointments\CreateAppointmentAction;
use App\Enums\AppointmentStatus;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\StoreAppointmentRequest;
use App\Models\Act;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Patient;
use App\Models\User;
use App\Services\Appointments\AvailabilityService;
use App\Services\DocumentBrandingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    /**
     * Display the booking calendar and the day's appointment list.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);

        /** @var User $user */
        $user = $request->user();

        $today = CarbonImmutable::now()->startOfDay();
        $year = (int) $request->integer('year', (int) $today->year);
        $month = (int) $request->integer('month', (int) $today->month);
        $month = max(1, min(12, $month));

        $filterDate = $this->resolveFilterDate($request, $today);
        $search = trim((string) $request->string('search'));
        $status = $this->resolveStatusFilter($request);
        $perPage = $this->resolvePerPage($request);

        $availability = AvailabilityService::forCurrentDoctor();

        return Inertia::render('appointments/Index', [
            'month' => $availability?->monthOverview($year, $month) ?? [
                'year' => $year,
                'month' => $month,
                'is_open_month' => false,
                'days' => [],
            ],
            'appointments' => $this->appointmentList($filterDate, $search, $status, $perPage, $today),
            'stats' => $this->statusCounts($filterDate, $search),
            'statusOptions' => $this->statusOptions(),
            'filters' => [
                'date' => $filterDate->toDateString(),
                'search' => $search,
                'status' => $status?->value,
                'per_page' => $perPage,
            ],
            'hasDoctor' => $availability !== null,
            'patients' => $this->patientOptions(),
            'prestations' => $this->prestationOptions(),
            'genders' => $this->genderOptions(),
            'bloodGroups' => $this->bloodGroupOptions(),
            'permissions' => [
                'book' => $user->can('appointments.create'),
                'confirm' => $user->can('appointments.update'),
                'checkIn' => $user->can('appointments.check-in'),
                'cancel' => $user->can('appointments.cancel'),
                'configure' => $user->can('appointments.configure'),
                'manageActs' => $user->can('configuration.manage'),
                'startConsultation' => $user->can('consultations.create'),
            ],
            'today' => $today->toDateString(),
        ]);
    }

    public function printList(
        Request $request,
        DocumentBrandingService $documentBranding,
    ): View {
        $this->authorize('viewAny', Appointment::class);

        $today = CarbonImmutable::now()->startOfDay();
        $filterDate = $this->resolveFilterDate($request, $today);
        $search = mb_substr(trim((string) $request->string('search')), 0, 120);
        $status = $this->resolveStatusFilter($request);
        $appointments = Appointment::query()
            ->with('patient:id,first_name,last_name,patient_number')
            ->when(
                $search === '',
                static fn (Builder $query): Builder => $query->whereDate(
                    'appointment_date',
                    $filterDate->toDateString(),
                ),
            )
            ->when($search !== '', $this->patientSearchFilter($search))
            ->when(
                $status !== null,
                static fn (Builder $query): Builder => $query->where(
                    'status',
                    $status->value,
                ),
            )
            ->when(
                $search !== '',
                static fn (Builder $query): Builder => $query->orderByDesc(
                    'appointment_date',
                ),
            )
            ->orderBy('starts_at')
            ->limit(1001)
            ->get();
        $truncated = $appointments->count() > 1000;

        return view('appointments.print-list', [
            'appointments' => $appointments
                ->take(1000)
                ->map(fn (Appointment $appointment): array => [
                    'date' => $appointment->appointment_date?->format('d/m/Y'),
                    'time' => $appointment->starts_at?->format('H:i'),
                    'patient_name' => $appointment->patient?->full_name,
                    'patient_number' => $appointment->patient?->patient_number,
                    'prestation' => $appointment->prestation,
                    'reason' => $appointment->reason,
                    'status' => $this->appointmentStatusLabel($appointment->status),
                ]),
            'branding' => $documentBranding->renderingIdentity(),
            'filterDate' => $filterDate->format('d/m/Y'),
            'search' => $search,
            'status' => $status === null
                ? 'Tous'
                : $this->appointmentStatusLabel($status),
            'truncated' => $truncated,
            'generatedAt' => CarbonImmutable::now()->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Book an appointment into a generated slot.
     */
    public function store(StoreAppointmentRequest $request, CreateAppointmentAction $action): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        /** @var User $user */
        $user = $request->user();

        $availability = AvailabilityService::forCurrentDoctor();

        if (! $availability instanceof AvailabilityService) {
            throw ValidationException::withMessages([
                'doctor' => 'No active doctor is configured for the cabinet.',
            ]);
        }

        $startsAt = CarbonImmutable::parse((string) $request->string('starts_at'));

        $slot = collect($availability->slotsForDate($startsAt)['slots'])
            ->first(static fn (array $slot): bool => $slot['starts_at'] === $startsAt->toIso8601String());

        if ($slot === null || $slot['available'] !== true) {
            throw ValidationException::withMessages([
                'starts_at' => 'That time slot is no longer available. Please choose another slot.',
            ]);
        }

        $patient = Patient::query()->findOrFail($request->integer('patient_id'));

        $action->handle($patient, $user, [
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($slot['ends_at']),
            'reason' => $request->input('reason'),
            'reception_notes' => $request->input('reception_notes'),
            'prestation' => $request->input('prestation'),
            'status' => $request->input('status'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment booked.')]);

        // Stay on the appointments list (scoped to the booked day) after booking.
        return to_route('app.appointments.index', [
            'year' => (int) $startsAt->year,
            'month' => (int) $startsAt->month,
            'date' => $startsAt->toDateString(),
        ]);
    }

    /**
     * Confirm a scheduled appointment.
     */
    public function confirm(Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        if ($appointment->status !== AppointmentStatus::SCHEDULED) {
            throw ValidationException::withMessages([
                'status' => 'Only scheduled appointments can be confirmed.',
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment confirmed.')]);

        return back();
    }

    /**
     * Mark a patient as checked in / arrived.
     */
    public function checkIn(Appointment $appointment): RedirectResponse
    {
        $this->authorize('checkIn', $appointment);

        if (! in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)) {
            throw ValidationException::withMessages([
                'status' => 'This appointment cannot be checked in.',
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::CHECKED_IN,
            'confirmed_at' => $appointment->confirmed_at ?? CarbonImmutable::now(),
            'checked_in_at' => CarbonImmutable::now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient checked in.')]);

        return back();
    }

    /**
     * Cancel an appointment, recording the required reason.
     */
    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('cancel', $appointment);

        if (in_array($appointment->status, [AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW], true)) {
            throw ValidationException::withMessages([
                'status' => 'This appointment can no longer be cancelled.',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $appointment->update([
            'status' => AppointmentStatus::CANCELLED,
            'cancelled_at' => CarbonImmutable::now(),
            'cancelled_by' => $request->user()?->id,
            'cancellation_reason' => $validated['reason'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment cancelled.')]);

        return back();
    }

    /**
     * Create a new act (prestation) available in the booking dialog.
     */
    public function storePrestation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $fee = ConsultationFee::query()->create([
            'label' => $validated['name'],
            'is_active' => true,
        ]);

        return response()->json([
            'prestation' => $this->prestationOption($fee),
        ]);
    }

    /**
     * Rename an existing act (prestation) from the booking dialog.
     */
    public function updatePrestation(Request $request, string $source, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ]);

        if ($source === 'consultation_fee') {
            $record = ConsultationFee::query()->findOrFail($id);
            $record->update(['label' => $validated['name']]);
        } elseif ($source === 'act') {
            $record = Act::query()->findOrFail($id);
            $record->update(['name' => $validated['name']]);
        } else {
            throw ValidationException::withMessages([
                'name' => 'Unknown prestation type.',
            ]);
        }

        return response()->json([
            'prestation' => $this->prestationOption($record),
        ]);
    }

    /**
     * Lightweight patient list used by the booking dialog.
     *
     * @return Collection<int, array{id: int, full_name: string, patient_number: string}>
     */
    private function patientOptions(): Collection
    {
        return Patient::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'patient_number'])
            ->map(static fn (Patient $patient): array => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'patient_number' => $patient->patient_number,
            ]);
    }

    /**
     * Resolve the day used to filter the table view, defaulting to today.
     */
    private function resolveFilterDate(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        if (! $request->filled('date')) {
            return $today;
        }

        try {
            return CarbonImmutable::parse((string) $request->string('date'))->startOfDay();
        } catch (\Throwable) {
            return $today;
        }
    }

    /**
     * Resolve the optional status filter.
     */
    private function resolveStatusFilter(Request $request): ?AppointmentStatus
    {
        $value = trim((string) $request->string('status'));

        if ($value === '' || $value === 'all') {
            return null;
        }

        return AppointmentStatus::tryFrom($value);
    }

    /**
     * Resolve the page size, restricted to a small whitelist.
     */
    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 10);

        return in_array($perPage, [10, 50, 100], true) ? $perPage : 10;
    }

    /**
     * Status filter options for the toolbar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            static fn (AppointmentStatus $status): array => [
                'value' => $status->value,
                'label' => ucwords(str_replace('_', ' ', $status->value)),
            ],
            AppointmentStatus::cases(),
        );
    }

    private function appointmentStatusLabel(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::SCHEDULED => 'Non confirmé',
            AppointmentStatus::CONFIRMED => 'Confirmé',
            AppointmentStatus::CHECKED_IN => 'Arrivé',
            AppointmentStatus::IN_PROGRESS => 'En cours',
            AppointmentStatus::COMPLETED => 'Traité',
            AppointmentStatus::CANCELLED => 'Annulé',
            AppointmentStatus::NO_SHOW => 'Absent',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function genderOptions(): array
    {
        return array_map(
            static fn (Gender $gender): array => [
                'value' => $gender->value,
                'label' => $gender->label(),
            ],
            Gender::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function bloodGroupOptions(): array
    {
        return array_map(
            static fn (BloodGroup $group): array => [
                'value' => $group->value,
                'label' => $group->value,
            ],
            BloodGroup::cases(),
        );
    }

    /**
     * Prestation options (consultation fees + acts) for the booking dialog.
     *
     * @return list<array{id: int, label: string, amount: float|null, category: string|null, source: string}>
     */
    private function prestationOptions(): array
    {
        $fees = ConsultationFee::query()->where('is_active', true)->orderBy('label')->get()
            ->map(fn (ConsultationFee $fee): array => $this->prestationOption($fee))
            ->all();

        $acts = Act::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Act $act): array => $this->prestationOption($act))
            ->all();

        return array_merge($fees, $acts);
    }

    /**
     * @return array{id: int, label: string, amount: float|null, category: string|null, source: string}
     */
    private function prestationOption(ConsultationFee|Act $record): array
    {
        if ($record instanceof ConsultationFee) {
            return [
                'id' => (int) $record->id,
                'label' => $record->label,
                'amount' => $record->amount_minor !== null ? $record->amount_minor / 100 : null,
                'category' => $record->category,
                'source' => 'consultation_fee',
            ];
        }

        return [
            'id' => (int) $record->id,
            'label' => $record->name,
            'amount' => $record->price_minor !== null ? $record->price_minor / 100 : null,
            'category' => $record->category,
            'source' => 'act',
        ];
    }

    /**
     * Per-status appointment counts for the day + search scope.
     *
     * @return array<string, int>
     */
    private function statusCounts(CarbonImmutable $date, string $search): array
    {
        $rows = Appointment::query()
            ->when($search === '', static fn (Builder $query): Builder => $query->whereDate('appointment_date', $date->toDateString()))
            ->when($search !== '', $this->patientSearchFilter($search))
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        $counts = [];
        $total = 0;

        foreach (AppointmentStatus::cases() as $case) {
            $count = (int) ($rows[$case->value] ?? 0);
            $counts[$case->value] = $count;
            $total += $count;
        }

        $counts['total'] = $total;

        return $counts;
    }

    /**
     * Paginated list of appointments for the table view, scoped to a single day.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function appointmentList(CarbonImmutable $date, string $search, ?AppointmentStatus $status, int $perPage, CarbonImmutable $today): LengthAwarePaginator
    {
        $paginator = Appointment::query()
            ->with('patient:id,first_name,last_name,patient_number')
            ->when($search === '', static fn (Builder $query): Builder => $query->whereDate('appointment_date', $date->toDateString()))
            ->when($search !== '', $this->patientSearchFilter($search))
            ->when($status !== null, static fn (Builder $query): Builder => $query->where('status', $status->value))
            ->when($search !== '', static fn (Builder $query): Builder => $query->orderByDesc('appointment_date'))
            ->orderBy('starts_at')
            ->paginate($perPage)
            ->withQueryString();

        // Map each appointment to its consultation (if the visit was already
        // started) so the list can offer "Continuer" / "Voir" alongside
        // "Démarrer la consultation" without an N+1 lookup.
        $consultations = Consultation::query()
            ->whereIn('appointment_id', $paginator->getCollection()->pluck('id')->all())
            ->get(['id', 'appointment_id', 'status'])
            ->keyBy('appointment_id');

        $todayString = $today->toDateString();

        return $paginator->through(function (Appointment $appointment) use ($consultations, $todayString): array {
            $consultation = $consultations->get($appointment->id);
            $isToday = $appointment->appointment_date?->toDateString() === $todayString;
            // A new consultation can only be started after the patient has
            // been checked in. Confirming an appointment must never open the
            // consultation workspace.
            $startableStatus = $appointment->status === AppointmentStatus::CHECKED_IN;

            return [
                'id' => $appointment->id,
                'date' => $appointment->appointment_date?->toDateString(),
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'time_label' => $appointment->starts_at?->format('H:i'),
                'end_label' => $appointment->ends_at?->format('H:i'),
                'patient_id' => $appointment->patient?->id,
                'patient_name' => $appointment->patient?->full_name,
                'patient_number' => $appointment->patient?->patient_number,
                'status' => $appointment->status->value,
                'reason' => $appointment->reason,
                'prestation' => $appointment->prestation,
                'cancellation_reason' => $appointment->cancellation_reason,
                'can_confirm' => $appointment->status === AppointmentStatus::SCHEDULED,
                'can_check_in' => in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true),
                'can_cancel' => ! in_array($appointment->status, [AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW], true),
                // Start-the-consultation affordances (requirement #2). Only
                // today's, non-terminal appointments may launch a new visit.
                'can_start' => $isToday && $startableStatus,
                'consultation_id' => $consultation?->id,
                'consultation_status' => $consultation?->status,
            ];
        });
    }

    /**
     * Reusable closure filtering appointments by their patient's name or phone.
     */
    private function patientSearchFilter(string $search): \Closure
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return static function (Builder $query) use ($like): void {
            $query->whereHas('patient', static function (Builder $patientQuery) use ($like): void {
                $patientQuery->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        };
    }
}
