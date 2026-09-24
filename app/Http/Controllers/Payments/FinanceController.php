<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Consultation;
use App\Models\Payment;
use App\Services\Billing\FinanceReport;
use App\Services\DocumentBrandingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceReport $report,
    ) {}

    /**
     * Yearly / monthly revenue analysis.
     */
    public function analytics(Request $request): Response
    {
        $now = CarbonImmutable::now();
        $years = $this->report->availableYears($now);
        [$year, $month] = $this->period($request, $now);

        if (! in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
        }

        return Inertia::render('payments/Analytics', [
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'years' => $years,
            'report' => $this->report->report($year, $month, $now),
            'receivables' => $this->report->receivables($now),
            // Costs include salaries: only for users who see reports.
            'profit' => $request->user()?->can('reports.view')
                ? $this->report->profit($year, $month, $now)
                : null,
        ]);
    }

    /**
     * Printable (A4) financial report for the same year / month, for the
     * accountant or the cabinet's records.
     */
    public function printAnalytics(Request $request, DocumentBrandingService $documentBranding): View
    {
        $now = CarbonImmutable::now();
        [$year, $month] = $this->period($request, $now);

        return view('payments.finance-report', [
            'branding' => $documentBranding->renderingIdentity(),
            'currency' => AccountingSetting::current()->currency ?? 'DA',
            'generatedAt' => $now->format('d/m/Y à H:i'),
            'report' => $this->report->report($year, $month, $now),
            'receivables' => $this->report->receivables($now),
            // Costs include salaries: only for users who see reports.
            'profit' => $request->user()?->can('reports.view')
                ? $this->report->profit($year, $month, $now)
                : null,
        ]);
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    private function period(Request $request, CarbonImmutable $now): array
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:'.($now->year + 1)],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return [
            (int) ($validated['year'] ?? $now->year),
            isset($validated['month']) ? (int) $validated['month'] : null,
        ];
    }

    /**
     * Cash journal (every collection and refund) for a date window, as a
     * CSV that opens directly in Excel / LibreOffice with French settings.
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($validated['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $currency = AccountingSetting::current()->currency ?? 'DA';
        $filename = sprintf('journal-encaissements_%s_%s.csv', $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($from, $to, $currency): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            // UTF-8 BOM so Excel shows accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Date', 'Type', 'N° patient', 'Patient', 'Prestation', 'Mode',
                'Montant ('.$currency.')', 'Reçu par', 'Consultation', 'Note', 'Référence',
            ], ';');

            $format = static fn (int $minor): string => number_format($minor / 100, 2, ',', '');
            // Neutralise spreadsheet formulas in free text (CSV injection).
            $text = static fn (mixed $value): string => preg_match('/^[=+\-@\t\r]/u', (string) $value) === 1
                ? "'".$value
                : (string) $value;

            Payment::query()
                ->whereBetween('received_at', [$from, $to])
                ->with([
                    'patient:id,first_name,last_name,patient_number',
                    'consultation:id,motif,payment_service',
                    'receivedBy:id,name',
                ])
                ->orderBy('received_at')
                ->orderBy('id')
                ->chunk(500, function ($payments) use ($out, $format, $text): void {
                    foreach ($payments as $payment) {
                        /** @var Payment $payment */
                        fputcsv($out, [
                            $payment->received_at?->format('d/m/Y H:i'),
                            $payment->isRefund() ? 'Remboursement' : 'Versement',
                            $text($payment->patient?->patient_number),
                            $text($payment->patient?->full_name),
                            $text($payment->consultation?->payment_service ?: ($payment->consultation?->motif ?: 'Consultation')),
                            $text($payment->method),
                            $format($payment->amount_minor),
                            $text($payment->receivedBy?->name),
                            $payment->consultation_id,
                            $text($payment->notes),
                            $payment->public_id,
                        ], ';');
                    }
                });

            // Paid consultations recorded before the ledger existed.
            Consultation::query()
                ->where('is_paid', true)
                ->where('payment_amount_minor', '>', 0)
                ->whereDoesntHave('payments')
                ->whereBetween('consulted_at', [$from, $to])
                ->with(['patient:id,first_name,last_name,patient_number', 'createdBy:id,name'])
                ->orderBy('consulted_at')
                ->each(function (Consultation $consultation) use ($out, $format, $text): void {
                    fputcsv($out, [
                        $consultation->consulted_at?->format('d/m/Y H:i'),
                        'Versement (historique)',
                        $text($consultation->patient->patient_number),
                        $text($consultation->patient->full_name),
                        $text($consultation->payment_service ?: ($consultation->motif ?: 'Consultation')),
                        $text($consultation->payment_method),
                        $format(max(0, (int) $consultation->payment_amount_minor - (int) $consultation->payment_adjustment_minor)),
                        $text($consultation->createdBy?->name),
                        $consultation->getKey(),
                        $text($consultation->payment_notes),
                        '',
                    ], ';');
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
