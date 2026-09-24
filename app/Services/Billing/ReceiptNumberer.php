<?php

namespace App\Services\Billing;

use App\Models\AccountingSetting;
use App\Models\Consultation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hands out receipt numbers such as « FACT-2026-00042 »: one gap-free
 * sequence per cabinet and fiscal year, using the prefix and fiscal-year
 * start configured in Configuration › Comptabilité.
 *
 * A consultation keeps its number forever once assigned.
 */
final class ReceiptNumberer
{
    private const SCOPE = 'receipt';

    public function assign(Consultation $consultation, ?CarbonImmutable $at = null): string
    {
        $existing = $consultation->getAttribute('receipt_number');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return DB::transaction(function () use ($consultation, $at): string {
            /** @var Consultation $locked */
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $existing = $locked->getAttribute('receipt_number');

            if (is_string($existing) && $existing !== '') {
                $consultation->setAttribute('receipt_number', $existing);

                return $existing;
            }

            $cabinetId = $locked->getAttribute('cabinet_id');
            $settings = AccountingSetting::current(is_numeric($cabinetId) ? (int) $cabinetId : null);
            $year = $this->fiscalYear($at ?? CarbonImmutable::now(), (string) ($settings->fiscal_year_start ?? '01-01'));
            $next = $this->next(is_numeric($cabinetId) ? (int) $cabinetId : null, $year);

            $number = sprintf('%s%d-%05d', trim((string) ($settings->receipt_prefix ?? '')), $year, $next);

            $locked->forceFill(['receipt_number' => $number])->saveQuietly();
            $consultation->setAttribute('receipt_number', $number);

            return $number;
        });
    }

    /**
     * The fiscal year is named after the calendar year it starts in.
     */
    public function fiscalYear(CarbonImmutable $at, string $start): int
    {
        $parts = explode('-', $start);
        $month = min(12, max(1, (int) ($parts[0] ?? 1)));
        $day = max(1, (int) ($parts[1] ?? 1));
        $monthStart = $at->setDate((int) $at->year, $month, 1)->startOfDay();
        $startThisYear = $monthStart->setDate((int) $at->year, $month, min($day, (int) $monthStart->daysInMonth));

        return $at->lessThan($startThisYear) ? (int) $at->year - 1 : (int) $at->year;
    }

    private function next(?int $cabinetId, int $year): int
    {
        $query = DB::table('document_sequences')
            ->where('scope', self::SCOPE)
            ->where('year', $year)
            ->when(
                $cabinetId === null,
                static fn ($q) => $q->whereNull('cabinet_id'),
                static fn ($q) => $q->where('cabinet_id', $cabinetId),
            );

        $row = (clone $query)->lockForUpdate()->first();

        if ($row === null) {
            DB::table('document_sequences')->insert([
                'cabinet_id' => $cabinetId,
                'scope' => self::SCOPE,
                'year' => $year,
                'last_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        }

        $next = (int) $row->last_value + 1;
        (clone $query)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }
}
