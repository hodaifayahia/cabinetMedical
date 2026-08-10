<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\RecordConsultationPaymentAction;
use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Act;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $filteredQuery = $this->filteredQuery($filters);
        $rowsQuery = clone $filteredQuery;
        $this->applyPaymentStatus($rowsQuery, $filters['status']);

        $payments = $rowsQuery
            ->with([
                'patient:id,first_name,last_name,patient_number',
                'createdBy:id,name',
                'payments.receivedBy:id,name',
            ])
            ->withSum('payments', 'amount_minor')
            ->orderByDesc('consulted_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $consultation): array => $this->paymentPayload($consultation));

        $summaryRows = (clone $filteredQuery)
            ->withSum('payments', 'amount_minor')
            ->get();
        $paidMinor = $summaryRows->sum(fn (Consultation $row): int => $row->collectedMinor());
        $outstandingMinor = $summaryRows->sum(fn (Consultation $row): int => $row->outstandingMinor());
        $todayMinor = (int) Payment::query()
            ->whereDate('received_at', now()->toDateString())
            ->sum('amount_minor');
        $todayMinor += (int) Consultation::query()
            ->where('is_paid', true)
            ->whereDoesntHave('payments')
            ->whereDate('consulted_at', now()->toDateString())
            ->sum('payment_amount_minor');

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'filters' => $filters,
            'summary' => [
                'today' => (int) $todayMinor / 100,
                'paid' => (int) $paidMinor / 100,
                'outstanding' => (int) $outstandingMinor / 100,
            ],
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'users' => User::query()
                ->when(
                    $request->user()?->cabinet_id !== null,
                    fn (Builder $query) => $query->where('cabinet_id', $request->user()?->cabinet_id),
                )
                ->orderBy('name')
                ->get(['id', 'name']),
            'methods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->values(),
            'services' => $this->services(),
            'canEdit' => $request->user()?->can('payments.create') ?? false,
        ]);
    }

    public function update(
        Request $request,
        Consultation $consultation,
        RecordConsultationPaymentAction $recordPayment,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'paid_today' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'settlement' => ['nullable', Rule::in(['debt', 'settled'])],
            'client_reference' => ['nullable', 'uuid'],
            'is_paid' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $chargeMinor = $this->toMinor($data['amount']);
        $existingPaidMinor = (int) $consultation->payments()->sum('amount_minor');
        $legacyPaid = ! array_key_exists('paid_today', $data)
            && (bool) ($data['is_paid'] ?? false);
        $paidTodayMinor = array_key_exists('paid_today', $data)
            ? $this->toMinor($data['paid_today'] ?? 0)
            : ($legacyPaid ? max(0, $chargeMinor - $existingPaidMinor) : 0);
        $settle = ($data['settlement'] ?? null) === 'settled'
            || (! isset($data['settlement']) && (bool) ($data['is_paid'] ?? false));

        $result = $recordPayment->handle($consultation, $actor, [
            'charge_minor' => $chargeMinor,
            'paid_now_minor' => $paidTodayMinor,
            'method' => $data['method'] ?? null,
            'service' => $data['service'] ?? null,
            'notes' => $data['notes'] ?? null,
            'settle' => $settle,
            'client_reference' => $data['client_reference'] ?? null,
        ]);

        // Keep the established audit event for integrations while the new
        // payment.collected event contains the immutable ledger detail.
        AuditLog::record('payment.updated', $consultation, [
            'changed_fields' => [
                'payment_amount_minor',
                'payment_method',
                'payment_service',
                'is_paid',
            ],
            'is_paid' => $result['status'] === 'paid',
            'paid_minor' => $result['paid_minor'],
            'outstanding_minor' => $result['outstanding_minor'],
        ], $actor->getKey());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Paiement mis à jour.',
        ]);

        return back();
    }

    public function store(
        Request $request,
        Consultation $consultation,
        RecordConsultationPaymentAction $recordPayment,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'paid_today' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'settlement' => ['required', Rule::in(['debt', 'settled'])],
            'client_reference' => ['required', 'uuid'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $result = $recordPayment->handle($consultation, $actor, [
            'charge_minor' => $this->toMinor($data['amount']),
            'paid_now_minor' => $this->toMinor($data['paid_today']),
            'method' => $data['method'] ?? null,
            'service' => $data['service'] ?? null,
            'notes' => $data['notes'] ?? null,
            'settle' => $data['settlement'] === 'settled',
            'client_reference' => $data['client_reference'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['outstanding_minor'] > 0
                ? 'Versement enregistré. Le solde reste en dette.'
                : 'Paiement enregistré et prestation soldée.',
        ]);

        return back();
    }

    public function printReport(
        Request $request,
        DocumentBrandingService $documentBranding,
    ): View {
        $filters = $this->validatedFilters($request);
        $query = $this->filteredQuery($filters);
        $this->applyPaymentStatus($query, $filters['status']);

        $payments = $query
            ->with(['patient:id,first_name,last_name,patient_number', 'payments.receivedBy:id,name'])
            ->withSum('payments', 'amount_minor')
            ->orderBy('consulted_at')
            ->get()
            ->map(fn (Consultation $consultation): array => $this->paymentPayload($consultation));

        return view('payments.report', [
            'branding' => $documentBranding->renderingIdentity(),
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'filters' => $filters,
            'payments' => $payments,
            'totals' => [
                'amount' => $payments->sum('amount'),
                'paid' => $payments->sum('paid'),
                'adjustment' => $payments->sum('adjustment'),
                'outstanding' => $payments->sum('outstanding'),
            ],
        ]);
    }

    public function printReceipt(
        Consultation $consultation,
        DocumentBrandingService $documentBranding,
    ): View {
        $consultation->load([
            'patient:id,first_name,last_name,patient_number',
            'payments.receivedBy:id,name',
        ])->loadSum('payments', 'amount_minor');

        return view('payments.receipt', [
            'branding' => $documentBranding->renderingIdentity(),
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'payment' => $this->paymentPayload($consultation),
        ]);
    }

    /**
     * @return array{from: string, to: string, user: string, search: string, status: string, method: string}
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'paid', 'unpaid', 'partial', 'debt'])],
            'method' => ['nullable', 'string', 'max:50'],
        ]);

        $status = (string) ($validated['status'] ?? 'all');
        $allTimeDebt = in_array($status, ['debt', 'unpaid', 'partial'], true)
            && ! $request->filled('from')
            && ! $request->filled('to');

        return [
            'from' => $allTimeDebt ? '' : (string) ($validated['from'] ?? now()->startOfMonth()->toDateString()),
            'to' => $allTimeDebt ? '' : (string) ($validated['to'] ?? now()->toDateString()),
            'user' => isset($validated['user']) ? (string) $validated['user'] : '',
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => $status,
            'method' => trim((string) ($validated['method'] ?? '')),
        ];
    }

    /**
     * @param  array{from: string, to: string, user: string, search: string, status: string, method: string}  $filters
     * @return Builder<Consultation>
     */
    private function filteredQuery(array $filters): Builder
    {
        return Consultation::query()
            ->whereNotNull('payment_amount_minor')
            ->when($filters['from'] !== '', fn (Builder $query) => $query->whereDate('consulted_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $query) => $query->whereDate('consulted_at', '<=', $filters['to']))
            ->when($filters['user'] !== '', fn (Builder $query) => $query->where('created_by', $filters['user']))
            ->when($filters['method'] !== '', fn (Builder $query) => $query->where('payment_method', $filters['method']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('payment_service', 'like', "%{$search}%")
                        ->orWhere('payment_method', 'like', "%{$search}%")
                        ->orWhereHas('patient', function (Builder $patientQuery) use ($search): void {
                            $patientQuery
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('patient_number', 'like', "%{$search}%");
                        });
                });
            });
    }

    /**
     * @param  Builder<Consultation>  $query
     */
    private function applyPaymentStatus(Builder $query, string $status): void
    {
        if ($status === 'paid') {
            $query->where('is_paid', true);
        } elseif (in_array($status, ['unpaid', 'debt'], true)) {
            $query->where('is_paid', false);
        } elseif ($status === 'partial') {
            $query->where('is_paid', false)->whereHas('payments');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Consultation $consultation): array
    {
        $amountMinor = (int) ($consultation->payment_amount_minor ?? 0);
        $paidMinor = $consultation->collectedMinor();
        $outstandingMinor = $consultation->outstandingMinor();
        $adjustmentMinor = (int) ($consultation->payment_adjustment_minor ?? 0);
        $patient = $consultation->patient;

        return [
            'id' => $consultation->getKey(),
            'patient_id' => $consultation->patient_id,
            'patient_number' => $patient->patient_number,
            'patient_name' => $patient->full_name,
            'initials' => Str::of($patient->full_name)
                ->explode(' ')
                ->filter()
                ->take(2)
                ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
                ->implode(''),
            'user_name' => $consultation->createdBy?->name,
            'service' => $consultation->payment_service ?: ($consultation->motif ?: __('Consultation')),
            'method' => $consultation->payment_method,
            'amount' => $amountMinor / 100,
            'paid' => $paidMinor / 100,
            'adjustment' => $adjustmentMinor / 100,
            'outstanding' => $outstandingMinor / 100,
            'status' => $consultation->paymentStatus(),
            'is_paid' => $consultation->paymentStatus() === 'paid',
            'notes' => $consultation->payment_notes,
            'installments' => $consultation->payments->map(fn (Payment $payment): array => [
                'id' => $payment->public_id,
                'amount' => $payment->amount_minor / 100,
                'method' => $payment->method,
                'notes' => $payment->notes,
                'received_at' => $payment->received_at?->toIso8601String(),
                'received_by' => $payment->receivedBy?->name,
            ])->values()->all(),
            'date' => $consultation->consulted_at?->toIso8601String(),
            'date_label' => $consultation->consulted_at?->format('d/m/Y H:i'),
        ];
    }

    private function toMinor(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages(['amount' => 'Le montant est invalide.']);
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return list<array{label: string, amount: float}>
     */
    private function services(): array
    {
        $fees = ConsultationFee::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(fn (ConsultationFee $fee): array => [
                'label' => $fee->label,
                'amount' => (float) ((int) ($fee->amount_minor ?? 0) / 100),
            ]);
        $acts = Act::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Act $act): array => [
                'label' => $act->name,
                'amount' => (float) ((int) ($act->price_minor ?? 0) / 100),
            ]);

        return array_values($fees->concat($acts)->unique('label')->values()->all());
    }
}
