<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Act;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            ])
            ->orderByDesc('consulted_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $consultation): array => $this->paymentPayload($consultation));

        $paidMinor = (clone $filteredQuery)
            ->where('is_paid', true)
            ->sum('payment_amount_minor');
        $outstandingMinor = (clone $filteredQuery)
            ->where('is_paid', false)
            ->sum('payment_amount_minor');
        $todayMinor = Consultation::query()
            ->where('is_paid', true)
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
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->values(),
            'services' => $this->services(),
            'canEdit' => $request->user()?->can('payments.create') ?? false,
        ]);
    }

    public function update(Request $request, Consultation $consultation): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:180'],
            'is_paid' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($consultation, $data, $request): void {
            $consultation->update([
                'payment_amount_minor' => (int) round(((float) $data['amount']) * 100),
                'payment_method' => $data['method'] ?? null,
                'payment_service' => $data['service'] ?? null,
                'is_paid' => (bool) $data['is_paid'],
            ]);

            AuditLog::record('payment.updated', $consultation, [
                'changed_fields' => collect(array_keys($consultation->getChanges()))
                    ->reject(static fn (string $field): bool => $field === 'updated_at')
                    ->values()
                    ->all(),
                'is_paid' => (bool) $data['is_paid'],
            ], $request->user()?->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Paiement mis à jour.',
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
            ->with(['patient:id,first_name,last_name,patient_number'])
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
                'outstanding' => $payments->sum('outstanding'),
            ],
        ]);
    }

    public function printReceipt(
        Consultation $consultation,
        DocumentBrandingService $documentBranding,
    ): View {
        $consultation->load('patient:id,first_name,last_name,patient_number');

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
            'status' => ['nullable', Rule::in(['all', 'paid', 'unpaid'])],
            'method' => ['nullable', 'string', 'max:50'],
        ]);

        return [
            'from' => (string) ($validated['from'] ?? now()->startOfMonth()->toDateString()),
            'to' => (string) ($validated['to'] ?? now()->toDateString()),
            'user' => isset($validated['user']) ? (string) $validated['user'] : '',
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? 'all'),
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
            ->whereDate('consulted_at', '>=', $filters['from'])
            ->whereDate('consulted_at', '<=', $filters['to'])
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
        } elseif ($status === 'unpaid') {
            $query->where('is_paid', false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Consultation $consultation): array
    {
        $amount = (int) ($consultation->payment_amount_minor ?? 0) / 100;
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
            'amount' => $amount,
            'paid' => $consultation->is_paid ? $amount : 0,
            'outstanding' => $consultation->is_paid ? 0 : $amount,
            'is_paid' => $consultation->is_paid,
            'date' => $consultation->consulted_at?->toIso8601String(),
            'date_label' => $consultation->consulted_at?->format('d/m/Y H:i'),
        ];
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
