<?php

namespace App\Actions\Payments;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\ReceiptNumberer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordConsultationPaymentAction
{
    public function __construct(
        private readonly ReceiptNumberer $receipts,
    ) {}

    /**
     * @param array{
     *     charge_minor: int,
     *     paid_now_minor: int,
     *     method?: string|null,
     *     service?: string|null,
     *     notes?: string|null,
     *     settle: bool,
     *     client_reference?: string|null
     * } $data
     * @return array{payment: Payment|null, charge_minor: int, paid_minor: int, adjustment_minor: int, outstanding_minor: int, status: string}
     */
    public function handle(Consultation $consultation, User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $consultation, $data): array {
            /** @var Consultation $locked */
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $chargeMinor = max(0, $data['charge_minor']);
            $paidNowMinor = max(0, $data['paid_now_minor']);
            $existingPaidMinor = $this->materializeLegacyCollection($locked, $actor)
                ?? (int) $locked->payments()->sum('amount_minor');
            $notes = trim((string) ($data['notes'] ?? ''));
            $clientReference = filled($data['client_reference'] ?? null)
                ? (string) $data['client_reference']
                : null;
            $payment = null;

            if ($chargeMinor < $existingPaidMinor) {
                throw ValidationException::withMessages([
                    'amount' => 'Le prix total ne peut pas être inférieur au montant déjà encaissé.',
                ]);
            }

            if ($paidNowMinor > 0 && $clientReference !== null) {
                $payment = $locked->payments()
                    ->where('client_reference', $clientReference)
                    ->first();
            }

            $maximumCollection = max(0, $chargeMinor - $existingPaidMinor);

            if (! $payment instanceof Payment && $paidNowMinor > $maximumCollection) {
                throw ValidationException::withMessages([
                    'paid_today' => 'Le montant versé dépasse le reste à payer.',
                ]);
            }

            if ($paidNowMinor > 0 && ! $payment instanceof Payment) {
                $payment = $locked->payments()->create([
                    'cabinet_id' => $locked->getAttribute('cabinet_id'),
                    'patient_id' => $locked->patient_id,
                    'amount_minor' => $paidNowMinor,
                    'method' => $data['method'] ?? null,
                    'notes' => $notes !== '' ? $notes : null,
                    'received_at' => now(),
                    'received_by' => $actor->getKey(),
                    'client_reference' => $clientReference,
                ]);
            }

            if ($payment instanceof Payment && $payment->wasRecentlyCreated) {
                // The first money received makes the receipt official.
                $this->receipts->assign($locked);
            }

            $paidMinor = $existingPaidMinor + ($payment instanceof Payment && $payment->wasRecentlyCreated
                ? $payment->amount_minor
                : 0);
            if ($payment instanceof Payment && ! $payment->wasRecentlyCreated) {
                // Idempotent retries return the state that already includes the
                // matching collection instead of counting it twice.
                $paidMinor = (int) $locked->payments()->sum('amount_minor');
            }

            $unsettledMinor = max(0, $chargeMinor - $paidMinor);
            $adjustmentMinor = (bool) $data['settle'] ? $unsettledMinor : 0;

            if ($adjustmentMinor > 0 && $notes === '') {
                throw ValidationException::withMessages([
                    'notes' => 'Indiquez la raison pour solder un montant inférieur au total.',
                ]);
            }

            $isPaid = $chargeMinor === 0 || $paidMinor + $adjustmentMinor >= $chargeMinor;
            $outstandingMinor = max(0, $chargeMinor - $paidMinor - $adjustmentMinor);

            $locked->update([
                'payment_amount_minor' => $chargeMinor,
                'payment_adjustment_minor' => $adjustmentMinor,
                'payment_method' => $data['method'] ?? null,
                'payment_service' => $data['service'] ?? null,
                'payment_notes' => $notes !== '' ? $notes : null,
                'is_paid' => $isPaid,
                'payment_settled_at' => $isPaid ? now() : null,
            ]);

            $status = $isPaid ? 'paid' : ($paidMinor > 0 ? 'partial' : 'unpaid');

            AuditLog::record('payment.collected', $locked, [
                'payment_id' => $payment?->getKey(),
                'charge_minor' => $chargeMinor,
                'collected_now_minor' => $payment?->wasRecentlyCreated ? $paidNowMinor : 0,
                'cumulative_paid_minor' => $paidMinor,
                'adjustment_minor' => $adjustmentMinor,
                'outstanding_minor' => $outstandingMinor,
                'status' => $status,
                'method' => $data['method'] ?? null,
                'notes_present' => $notes !== '',
            ], $actor->getKey());

            return [
                'payment' => $payment,
                'charge_minor' => $chargeMinor,
                'paid_minor' => $paidMinor,
                'adjustment_minor' => $adjustmentMinor,
                'outstanding_minor' => $outstandingMinor,
                'status' => $status,
            ];
        });
    }

    /**
     * Consultations marked paid before the payment ledger existed (or
     * imported) have no instalment: their whole price counts as collected
     * (see Consultation::collectedMinor()). Editing one must not turn that
     * money back into debt, so the historical collection is written to the
     * ledger first, dated like the reports already count it (consultation
     * date). Returns the collected amount, or null for a regular record.
     */
    private function materializeLegacyCollection(Consultation $locked, User $actor): ?int
    {
        $amountMinor = (int) ($locked->payment_amount_minor ?? 0);

        if (! $locked->is_paid
            || $amountMinor <= 0
            || (int) ($locked->payment_adjustment_minor ?? 0) !== 0
            || $locked->payments()->exists()) {
            return null;
        }

        $locked->payments()->create([
            'cabinet_id' => $locked->getAttribute('cabinet_id'),
            'patient_id' => $locked->patient_id,
            'amount_minor' => $amountMinor,
            'method' => $locked->payment_method,
            'notes' => 'Encaissement antérieur au journal des versements',
            'received_at' => $locked->consulted_at ?? now(),
            'received_by' => $locked->created_by ?? $actor->getKey(),
        ]);

        return $amountMinor;
    }
}
