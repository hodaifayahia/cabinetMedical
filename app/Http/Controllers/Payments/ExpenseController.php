<?php

namespace App\Http\Controllers\Payments;

use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cabinet operating costs. Restricted to `reports.view` (doctor by default):
 * salaries and rent are not shown to assistants.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);

        $byCategory = (clone $query)
            ->get(['category', 'amount_minor'])
            ->groupBy(static fn (Expense $expense): string => $expense->category->value)
            ->map(static fn ($rows, string $category): array => [
                'category' => $category,
                'label' => ExpenseCategory::from($category)->label(),
                'amount' => (int) $rows->sum('amount_minor') / 100,
                'count' => $rows->count(),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $expenses = (clone $query)
            ->with('createdBy:id,name')
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Expense $expense): array => $this->payload($expense));

        return Inertia::render('payments/Expenses', [
            'expenses' => $expenses,
            'filters' => $filters,
            'totals' => [
                'amount' => array_sum(array_column($byCategory, 'amount')),
                'count' => array_sum(array_column($byCategory, 'count')),
                'byCategory' => $byCategory,
            ],
            'categories' => ExpenseCategory::options(),
            'methods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->pluck('name')->values(),
            'currency' => AccountingSetting::current()->currency ?? 'DA',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $expense = Expense::query()->create([
            ...$data,
            'created_by' => $request->user()?->getKey(),
        ]);

        AuditLog::record('expense.created', $expense, [
            'category' => $expense->category->value,
            'amount_minor' => $expense->amount_minor,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Charge enregistrée.']);

        return back();
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $before = $expense->amount_minor;
        $expense->update($this->validated($request));

        AuditLog::record('expense.updated', $expense, [
            'category' => $expense->category->value,
            'amount_minor_before' => $before,
            'amount_minor' => $expense->amount_minor,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Charge modifiée.']);

        return back();
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        AuditLog::record('expense.deleted', $expense, [
            'category' => $expense->category->value,
            'label' => $expense->label,
            'amount_minor' => $expense->amount_minor,
            'spent_on' => $expense->spent_on->toDateString(),
        ]);
        $expense->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Charge supprimée.']);

        return back();
    }

    /**
     * Copies last month's recurring charges (rent, salaries…) into the
     * requested month, skipping those already entered.
     */
    public function copyRecurring(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $target = CarbonImmutable::createFromFormat('!Y-m', $data['month']) ?: CarbonImmutable::now()->startOfMonth();
        $source = $target->subMonthNoOverflow();

        $existing = Expense::query()
            ->spentBetween($target->startOfMonth()->toDateString(), $target->endOfMonth()->toDateString())
            ->get(['category', 'label'])
            ->map(static fn (Expense $expense): string => $expense->category->value.'|'.mb_strtolower($expense->label))
            ->all();

        $copied = 0;

        Expense::query()
            ->where('is_recurring', true)
            ->spentBetween($source->startOfMonth()->toDateString(), $source->endOfMonth()->toDateString())
            ->get()
            ->each(function (Expense $expense) use ($target, $existing, $request, &$copied): void {
                if (in_array($expense->category->value.'|'.mb_strtolower($expense->label), $existing, true)) {
                    return;
                }

                $day = min((int) $expense->spent_on->day, (int) $target->daysInMonth);
                Expense::query()->create([
                    'category' => $expense->category,
                    'label' => $expense->label,
                    'amount_minor' => $expense->amount_minor,
                    'spent_on' => $target->setDate((int) $target->year, (int) $target->month, $day)->toDateString(),
                    'method' => $expense->method,
                    'supplier' => $expense->supplier,
                    'notes' => $expense->notes,
                    'is_recurring' => true,
                    'created_by' => $request->user()?->getKey(),
                ]);
                $copied++;
            });

        AuditLog::record('expense.recurring_copied', null, ['month' => $data['month'], 'count' => $copied]);

        Inertia::flash('toast', [
            'type' => $copied > 0 ? 'success' : 'info',
            'message' => $copied > 0
                ? $copied.' charge(s) récurrente(s) ajoutée(s).'
                : 'Aucune charge récurrente à reporter.',
        ]);

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $currency = AccountingSetting::current()->currency ?? 'DA';
        $query = $this->filtered($filters)->with('createdBy:id,name')->orderBy('spent_on')->orderBy('id');

        return response()->streamDownload(function () use ($query, $currency): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            $text = static fn (mixed $value): string => preg_match('/^[=+\-@\t\r]/u', (string) $value) === 1
                ? "'".$value
                : (string) $value;

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Catégorie', 'Libellé', 'Fournisseur', 'Mode', 'Montant ('.$currency.')', 'Récurrente', 'Saisie par', 'Note'], ';');

            $query->chunk(500, function ($expenses) use ($out, $text): void {
                foreach ($expenses as $expense) {
                    /** @var Expense $expense */
                    fputcsv($out, [
                        $expense->spent_on->format('d/m/Y'),
                        $expense->category->label(),
                        $text($expense->label),
                        $text($expense->supplier),
                        $text($expense->method),
                        number_format($expense->amount_minor / 100, 2, ',', ''),
                        $expense->is_recurring ? 'Oui' : 'Non',
                        $text($expense->createdBy?->name),
                        $text($expense->notes),
                    ], ';');
                }
            });

            fclose($out);
        }, sprintf('charges_%s_%s.csv', $filters['from'], $filters['to']), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{from: string, to: string, category: string, search: string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'from' => (string) ($validated['from'] ?? now()->startOfMonth()->toDateString()),
            'to' => (string) ($validated['to'] ?? now()->endOfMonth()->toDateString()),
            'category' => (string) ($validated['category'] ?? ''),
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }

    /**
     * @param  array{from: string, to: string, category: string, search: string}  $filters
     * @return Builder<Expense>
     */
    private function filtered(array $filters): Builder
    {
        return Expense::query()
            ->spentBetween($filters['from'], $filters['to'])
            ->when($filters['category'] !== '', static fn (Builder $query) => $query->where('category', $filters['category']))
            ->when($filters['search'] !== '', static function (Builder $query) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $query->where(static fn (Builder $nested) => $nested
                    ->where('label', 'like', $like)
                    ->orWhere('supplier', 'like', $like)
                    ->orWhere('notes', 'like', $like));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'label' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'spent_on' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'supplier' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['sometimes', 'boolean'],
        ]);

        return [
            'category' => $data['category'],
            'label' => trim((string) $data['label']),
            'amount_minor' => (int) round(((float) $data['amount']) * 100),
            'spent_on' => $data['spent_on'],
            'method' => filled($data['method'] ?? null) ? trim((string) $data['method']) : null,
            'supplier' => filled($data['supplier'] ?? null) ? trim((string) $data['supplier']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'is_recurring' => (bool) ($data['is_recurring'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Expense $expense): array
    {
        return [
            'id' => $expense->public_id,
            'category' => $expense->category->value,
            'category_label' => $expense->category->label(),
            'label' => $expense->label,
            'amount' => $expense->amount_minor / 100,
            'spent_on' => $expense->spent_on->toDateString(),
            'method' => $expense->method,
            'supplier' => $expense->supplier,
            'notes' => $expense->notes,
            'is_recurring' => $expense->is_recurring,
            'created_by' => $expense->createdBy?->name,
        ];
    }
}
