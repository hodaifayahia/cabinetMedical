<?php

namespace App\Actions\Payments;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns money from one collected instalment. The refund is a new negative
 * ledger entry (the original instalment is never edited), so the cash
 * journal shows both the collection and the reversal on their real dates.
 */
final class RefundConsultationPaymentAction
{
    /**
     * @param array{
     *     payment_public_id: string,
     *     amount_minor: int,
     *     reason: string,
     *     reduce_charge: bool,
     *     client_reference?: string|null
     * } $data
     * @return array{refund: Payment, charge_minor: int, paid_minor: int, outstanding_minor: int, status: string}
     */
    public function handle(Consultation $consultation, User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $consultation, $data): array {
            /** @var Consultation $locked */
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $reason = trim($data['reason']);
            $clientReference = filled($data['client_reference'] ?? null)
                ? (string) $data['client_reference']
                : null;

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Indiquez le motif du remboursement.',
                ]);
            }

            /** @var Payment|null $original */
            $original = $locked->payments()
                ->where('public_id', $data['payment_public_id'])
                ->first();

            if (! $original instanceof Payment || $original->isRefund()) {
                throw ValidationException::withMessages([
                    'payment_id' => 'Ce versement est introuvable ou ne peut pas être remboursé.',
                ]);
            }

            if ($clientReference !== null) {
                $existing = $locked->payments()
                    ->where('client_reference', $clientReference)
                    ->first();

                if ($existing instanceof Payment) {
                    // Idempotent retry of a refund that already went through.
                    return $this->result($locked, $existing);
                }
            }

            $alreadyRefundedMinor = (int) -Payment::query()
                ->where('refund_of_payment_id', $original->getKey())
                ->sum('amount_minor');
            $refundableMinor = max(0, $original->amount_minor - $alreadyRefundedMinor);
            $amountMinor = $data['amount_minor'];

            if ($amountMinor <= 0 || $amountMinor > $refundableMinor) {
                throw ValidationException::withMessages([
                    'amount' => 'Le montant à rembourser doit être compris entre 0 et '
                        .number_format($refundableMinor / 100, 2, ',', ' ').'.',
                ]);
            }

            $refund = $locked->payments()->create([
                'cabinet_id' => $locked->getAttribute('cabinet_id'),
                'patient_id' => $locked->patient_id,
                'refund_of_payment_id' => $original->getKey(),
                'amount_minor' => -$amountMinor,
                'method' => $original->method,
                'notes' => $reason,
                'received_at' => now(),
                'received_by' => $actor->getKey(),
                'client_reference' => $clientReference,
            ]);

            $chargeMinor = (int) ($locked->payment_amount_minor ?? 0);

            if ($data['reduce_charge']) {
                $chargeMinor = max(0, $chargeMinor - $amountMinor);
            }

            $paidMinor = (int) $locked->payments()->sum('amount_minor');
            $adjustmentMinor = min(
                (int) ($locked->payment_adjustment_minor ?? 0),
                max(0, $chargeMinor - $paidMinor),
            );
            $isPaid = $chargeMinor === 0 || $paidMinor + $adjustmentMinor >= $chargeMinor;

            $locked->update([
                'payment_amount_minor' => $chargeMinor,
                'payment_adjustment_minor' => $adjustmentMinor,
                'is_paid' => $isPaid,
                'payment_settled_at' => $isPaid ? ($locked->payment_settled_at ?? now()) : null,
            ]);

            $result = $this->result($locked->refresh(), $refund);

            AuditLog::record('payment.refunded', $locked, [
                'payment_id' => $refund->getKey(),
                'refund_of_payment_id' => $original->getKey(),
                'refunded_minor' => $amountMinor,
                'reduce_charge' => $data['reduce_charge'],
                'charge_minor' => $result['charge_minor'],
                'cumulative_paid_minor' => $result['paid_minor'],
                'outstanding_minor' => $result['outstanding_minor'],
                'status' => $result['status'],
            ], $actor->getKey());

            return $result;
        });
    }

    /**
     * @return array{refund: Payment, charge_minor: int, paid_minor: int, outstanding_minor: int, status: string}
     */
    private function result(Consultation $consultation, Payment $refund): array
    {
        return [
            'refund' => $refund,
            'charge_minor' => (int) ($consultation->payment_amount_minor ?? 0),
            'paid_minor' => (int) $consultation->payments()->sum('amount_minor'),
            'outstanding_minor' => $consultation->outstandingMinor(),
            'status' => $consultation->paymentStatus(),
        ];
    }
}
