<?php

namespace App\Http\Controllers\Payments\Concerns;

use App\Models\Consultation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The payments journal filters (period, user, status, method, search),
 * shared by the on-screen journal, its printable report and the CSV export
 * so the three always describe the same consultations.
 */
trait FiltersPaymentJournal
{
    /**
     * @return array{from: string, to: string, user: string, search: string, status: string, method: string}
     */
    protected function validatedFilters(Request $request): array
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
     * Consultations matching the filters, without the payment status (the
     * summary totals need every status of the period).
     *
     * @param  array{from: string, to: string, user: string, search: string, status: string, method: string}  $filters
     * @return Builder<Consultation>
     */
    protected function filteredQuery(array $filters): Builder
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
    protected function applyPaymentStatus(Builder $query, string $status): void
    {
        if ($status === 'paid') {
            $query->where('is_paid', true);
        } elseif (in_array($status, ['unpaid', 'debt'], true)) {
            $query->where('is_paid', false);
        } elseif ($status === 'partial') {
            $query->where('is_paid', false)->whereHas('payments');
        }
    }
}
