<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The cabinet's AI wallet. Credits are taken *before* the provider is called,
 * with a single conditional UPDATE, so two doctors clicking at once can never
 * spend the same credit twice; a failed call gives them back.
 *
 * No row lock is held across the provider call, which can take many seconds.
 */
final class AiCreditLedger
{
    public function balance(Cabinet $cabinet): int
    {
        return (int) Cabinet::query()->whereKey($cabinet->getKey())->value('ai_credits');
    }

    /**
     * Run `$call` against the wallet: charge, call, refund on failure.
     *
     * @param  callable(): AiCompletion  $call
     */
    public function spend(Cabinet $cabinet, ?User $user, AiFeature $feature, callable $call): AiCompletion
    {
        if (! (bool) Cabinet::query()->whereKey($cabinet->getKey())->value('ai_enabled')) {
            throw new AiException(
                'L’assistant IA est désactivé pour ce cabinet. Contactez le support pour l’activer.',
                AiException::DISABLED,
                $this->balance($cabinet),
            );
        }

        $cost = $feature->cost();
        $taken = $cost === 0 || Cabinet::query()
            ->whereKey($cabinet->getKey())
            ->where('ai_credits', '>=', $cost)
            ->decrement('ai_credits', $cost) === 1;

        if (! $taken) {
            throw AiException::insufficientCredits($this->balance($cabinet), $cost);
        }

        $lastLineId = $cost > 0
            ? (int) AiUsage::query()->where('cabinet_id', $cabinet->getKey())->max('id')
            : 0;

        try {
            $completion = $call();
        } catch (\Throwable $exception) {
            if ($cost > 0) {
                $this->refund($cabinet, $cost, $lastLineId);
            }

            throw $exception;
        }

        $balance = $this->balance($cabinet);

        AiUsage::query()->create([
            'cabinet_id' => $cabinet->getKey(),
            'user_id' => $user?->getKey(),
            'feature' => $feature->value,
            'credits' => -$cost,
            'balance_after' => $balance,
            'status' => AiUsage::STATUS_CHARGED,
            'model' => $completion->model,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
        ]);

        return $completion->withBalance($balance);
    }

    /**
     * Give a failed call's credits back, unless a platform admin cut or reset
     * the wallet while the call was running. That figure was set against a
     * balance that already excluded this charge, so adding the credits back
     * would undo it: a cabinet cut off at 0 would be able to spend again. A
     * recharge made meanwhile does not stop the refund.
     *
     * The cabinet row is locked as adjust() locks it, so the two cannot
     * interleave.
     */
    private function refund(Cabinet $cabinet, int $cost, int $lastLineId): void
    {
        DB::transaction(function () use ($cabinet, $cost, $lastLineId): void {
            Cabinet::query()->whereKey($cabinet->getKey())->lockForUpdate()->first();

            $cutMeanwhile = AiUsage::query()
                ->where('cabinet_id', $cabinet->getKey())
                ->where('id', '>', $lastLineId)
                ->where('status', AiUsage::STATUS_ADJUSTED)
                ->where('credits', '<=', 0)
                ->exists();

            if (! $cutMeanwhile) {
                Cabinet::query()->whereKey($cabinet->getKey())->increment('ai_credits', $cost);
            }
        });
    }

    /**
     * A platform admin's recharge, deduction or reset. Returns the new balance.
     */
    public function adjust(Cabinet $cabinet, ?User $admin, string $operation, int $amount, ?string $note = null): int
    {
        $amount = max(0, $amount);

        return DB::transaction(function () use ($cabinet, $admin, $operation, $amount, $note): int {
            $locked = Cabinet::query()->whereKey($cabinet->getKey())->lockForUpdate()->firstOrFail();
            $before = (int) $locked->ai_credits;
            $after = match ($operation) {
                'add' => $before + $amount,
                'remove' => max(0, $before - $amount),
                'set' => $amount,
                default => throw new \InvalidArgumentException("Unknown operation [{$operation}]."),
            };

            Cabinet::query()->whereKey($locked->getKey())->update(['ai_credits' => $after]);

            AiUsage::query()->create([
                'cabinet_id' => $locked->getKey(),
                'user_id' => $admin?->getKey(),
                'feature' => 'admin_adjustment',
                'credits' => $after - $before,
                'balance_after' => $after,
                'status' => AiUsage::STATUS_ADJUSTED,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            ]);

            return $after;
        });
    }
}
