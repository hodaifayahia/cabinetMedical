<?php

namespace App\Actions\Payments;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordConsultationPaymentAction
{
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
            $existingPaidMinor = (int) $locked->payments()->sum('amount_minor');
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
}
