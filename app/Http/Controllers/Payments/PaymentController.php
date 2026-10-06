<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\RecordConsultationPaymentAction;
use App\Actions\Payments\RefundConsultationPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payments\Concerns\FiltersPaymentJournal;
use App\Models\AccountingSetting;
use App\Models\Act;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Billing\ReceiptNumberer;
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
    use FiltersPaymentJournal;

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
        // The « Dettes » shortcut opens every open debt whatever the period,
        // so its badge must count all of them, not only the filtered window.
        $debtsMinor = Consultation::query()
            ->where('is_paid', false)
            ->where('payment_amount_minor', '>', 0)
            ->withSum('payments', 'amount_minor')
            ->get(['id', 'payment_amount_minor', 'payment_adjustment_minor', 'is_paid'])
            ->sum(fn (Consultation $row): int => $row->outstandingMinor());

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'filters' => $filters,
            'summary' => [
                'today' => (int) $todayMinor / 100,
                'paid' => (int) $paidMinor / 100,
                'outstanding' => (int) $outstandingMinor / 100,
                'debts' => (int) $debtsMinor / 100,
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
            'canRefund' => $request->user()?->can('payments.refund') ?? false,
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

    public function refund(
        Request $request,
        Consultation $consultation,
        RefundConsultationPaymentAction $refundPayment,
    ): RedirectResponse {
        $data = $request->validate([
            'payment_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'reason' => ['required', 'string', 'max:2000'],
            'reduce_charge' => ['required', 'boolean'],
            'client_reference' => ['required', 'uuid'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $result = $refundPayment->handle($consultation, $actor, [
            'payment_public_id' => $data['payment_id'],
            'amount_minor' => $this->toMinor($data['amount']),
            'reason' => $data['reason'],
            'reduce_charge' => (bool) $data['reduce_charge'],
            'client_reference' => $data['client_reference'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['outstanding_minor'] > 0
                ? 'Remboursement enregistré. Le montant remboursé redevient dû.'
                : 'Remboursement enregistré.',
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
        ReceiptNumberer $receipts,
    ): View {
        $consultation->load([
            'patient:id,first_name,last_name,patient_number',
            'payments.receivedBy:id,name',
        ])->loadSum('payments', 'amount_minor');

        // Consultations paid before numbering existed get their number the
        // first time their receipt is printed.
        if ($consultation->collectedMinor() > 0) {
            $receipts->assign($consultation);
        }

        return view('payments.receipt', [
            'branding' => $documentBranding->renderingIdentity(),
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'payment' => $this->paymentPayload($consultation),
        ]);
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
            'receipt_number' => $consultation->receipt_number,
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
            // The stored value only (the label above falls back to the visit
            // motif, which can be longer than a service and must not be saved
            // back as one when the payment is edited).
            'payment_service' => $consultation->payment_service,
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
                'is_refund' => $payment->isRefund(),
                'refundable' => $payment->isRefund()
                    ? 0
                    : max(0, $payment->amount_minor + (int) $consultation->payments
                        ->where('refund_of_payment_id', $payment->getKey())
                        ->sum('amount_minor')) / 100,
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
