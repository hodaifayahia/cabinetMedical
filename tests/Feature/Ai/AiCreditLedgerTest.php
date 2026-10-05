<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Ai\AiCompletion;
use App\Services\Ai\AiCreditLedger;
use App\Services\Ai\AiException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The cabinet's AI wallet: charge first, refund a failed call, never let the
 * balance go negative.
 */
class AiCreditLedgerTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private AiCreditLedger $ledger;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->ledger = app(AiCreditLedger::class);
        $this->cabinet = $this->makeCabinet();
        $this->doctor = $this->makeDoctor($this->cabinet);
    }

    private function setBalance(int $credits): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_credits' => $credits]);
    }

    private function ok(): AiCompletion
    {
        return new AiCompletion('{}', 'qwen-test', 900, 150);
    }

    public function test_a_new_cabinet_starts_with_the_initial_credits(): void
    {
        $this->assertSame((int) config('ai.initial_credits'), $this->ledger->balance($this->cabinet));
        $this->assertTrue((bool) $this->cabinet->fresh()->ai_enabled);
    }

    public function test_balance_reads_the_database_not_the_loaded_model(): void
    {
        $this->setBalance(42);

        $this->assertSame(42, $this->ledger->balance($this->cabinet));
    }

    public function test_a_spend_charges_the_price_and_records_a_ledger_line(): void
    {
        $completion = $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::PATIENT_ANALYSIS, fn () => $this->ok());

        $this->assertSame(495, $completion->balance);
        $this->assertSame(495, $this->ledger->balance($this->cabinet));

        $line = AiUsage::query()->sole();
        $this->assertSame($this->cabinet->getKey(), $line->cabinet_id);
        $this->assertSame($this->doctor->getKey(), $line->user_id);
        $this->assertSame('patient_analysis', $line->feature);
        $this->assertSame(-5, $line->credits);
        $this->assertSame(495, $line->balance_after);
        $this->assertSame(AiUsage::STATUS_CHARGED, $line->status);
        $this->assertSame('qwen-test', $line->model);
        $this->assertSame(900, $line->prompt_tokens);
        $this->assertSame(150, $line->completion_tokens);
    }

    public function test_a_spend_without_a_user_is_still_recorded(): void
    {
        $this->ledger->spend($this->cabinet, null, AiFeature::CONSULTATION_TEXT, fn () => $this->ok());

        $this->assertNull(AiUsage::query()->sole()->user_id);
    }

    public function test_the_last_credits_can_be_spent_exactly(): void
    {
        $this->setBalance(5);

        $completion = $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::PATIENT_ANALYSIS, fn () => $this->ok());

        $this->assertSame(0, $completion->balance);
    }

    public function test_a_wallet_short_of_the_price_refuses_without_calling(): void
    {
        $this->setBalance(4);
        $called = false;

        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::PATIENT_ANALYSIS, function () use (&$called): AiCompletion {
                $called = true;

                return $this->ok();
            });
            $this->fail('The spend should have been refused.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::INSUFFICIENT_CREDITS, $exception->reason);
            $this->assertSame(4, $exception->balance);
        }

        $this->assertFalse($called);
        $this->assertSame(4, $this->ledger->balance($this->cabinet));
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_successive_spends_stop_exactly_when_the_wallet_is_empty(): void
    {
        $this->setBalance(3);
        $served = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::CONSULTATION_TEXT, fn () => $this->ok());
                $served++;
            } catch (AiException) {
                // Refused once the wallet is empty.
            }
        }

        $this->assertSame(3, $served);
        $this->assertSame(0, $this->ledger->balance($this->cabinet));
        $this->assertSame(3, AiUsage::query()->count());
    }

    public function test_a_disabled_cabinet_is_refused_without_calling(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_enabled' => false]);

        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::CONSULTATION_TEXT, fn () => $this->fail('Must not be called.'));
            $this->fail('The spend should have been refused.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::DISABLED, $exception->reason);
            $this->assertSame(500, $exception->balance);
        }

        $this->assertSame(500, $this->ledger->balance($this->cabinet));
    }

    public function test_the_disabled_flag_is_read_fresh_from_the_database(): void
    {
        $loaded = $this->cabinet->fresh();
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_enabled' => false]);

        $this->expectException(AiException::class);

        $this->ledger->spend($loaded, $this->doctor, AiFeature::CONSULTATION_TEXT, fn () => $this->ok());
    }

    public function test_any_failure_during_the_call_gives_the_credits_back(): void
    {
        foreach ([new AiException('down', AiException::PROVIDER), new LogicException('bug'), new \TypeError('type')] as $failure) {
            try {
                $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::ECG_ANALYSIS, fn () => throw $failure);
            } catch (\Throwable $caught) {
                $this->assertSame($failure, $caught);
            }

            $this->assertSame(500, $this->ledger->balance($this->cabinet), $failure::class);
        }

        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_a_free_feature_is_served_and_recorded_without_a_charge(): void
    {
        config(['ai.costs.copilot_chat' => 0]);
        $this->setBalance(0);

        $completion = $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::COPILOT_CHAT, fn () => $this->ok());

        $this->assertSame(0, $completion->balance);
        $this->assertSame(0, AiUsage::query()->sole()->credits);
    }

    public function test_a_free_feature_failure_does_not_mint_credits(): void
    {
        config(['ai.costs.copilot_chat' => 0]);
        $this->setBalance(7);

        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::COPILOT_CHAT, fn () => throw new LogicException('x'));
        } catch (LogicException) {
        }

        $this->assertSame(7, $this->ledger->balance($this->cabinet));
    }

    public function test_spending_one_cabinet_never_touches_another(): void
    {
        $other = $this->makeCabinet('Autre');

        $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::DOCUMENT_ANALYSIS, fn () => $this->ok());

        $this->assertSame(497, $this->ledger->balance($this->cabinet));
        $this->assertSame(500, $this->ledger->balance($other));
    }

    public function test_a_reset_to_zero_during_a_failed_call_is_kept(): void
    {
        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::ECG_ANALYSIS, function (): never {
                $this->ledger->adjust($this->cabinet, null, 'set', 0);

                throw new LogicException('timeout');
            });
        } catch (LogicException) {
        }

        $this->assertSame(0, $this->ledger->balance($this->cabinet));
    }

    public function test_a_deduction_during_a_failed_call_is_kept(): void
    {
        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::ECG_ANALYSIS, function (): never {
                $this->ledger->adjust($this->cabinet, null, 'remove', 100);

                throw new LogicException('timeout');
            });
        } catch (LogicException) {
        }

        // 500 - 4 charged - 100 removed; the 4 are not given back.
        $this->assertSame(396, $this->ledger->balance($this->cabinet));
    }

    public function test_an_older_adjustment_does_not_block_a_later_refund(): void
    {
        $this->ledger->adjust($this->cabinet, null, 'remove', 100);

        try {
            $this->ledger->spend($this->cabinet, $this->doctor, AiFeature::ECG_ANALYSIS, fn () => throw new LogicException('timeout'));
        } catch (LogicException) {
        }

        $this->assertSame(400, $this->ledger->balance($this->cabinet));
    }

    public function test_adjust_adds_removes_and_sets(): void
    {
        $this->assertSame(600, $this->ledger->adjust($this->cabinet, $this->doctor, 'add', 100));
        $this->assertSame(550, $this->ledger->adjust($this->cabinet, $this->doctor, 'remove', 50));
        $this->assertSame(0, $this->ledger->adjust($this->cabinet, $this->doctor, 'remove', 10000));
        $this->assertSame(75, $this->ledger->adjust($this->cabinet, $this->doctor, 'set', 75));
        $this->assertSame(75, $this->ledger->balance($this->cabinet));

        $lines = AiUsage::query()->orderBy('id')->get();
        $this->assertSame([100, -50, -550, 75], $lines->pluck('credits')->all());
        $this->assertSame([600, 550, 0, 75], $lines->pluck('balance_after')->all());
        $this->assertSame(['admin_adjustment'], $lines->pluck('feature')->unique()->values()->all());
        $this->assertSame([AiUsage::STATUS_ADJUSTED], $lines->pluck('status')->unique()->values()->all());
        $this->assertSame([$this->doctor->getKey()], $lines->pluck('user_id')->unique()->values()->all());
    }

    public function test_a_negative_amount_is_treated_as_zero(): void
    {
        $this->assertSame(500, $this->ledger->adjust($this->cabinet, null, 'add', -100));
        $this->assertSame(500, $this->ledger->adjust($this->cabinet, null, 'remove', -100));
        $this->assertSame(0, $this->ledger->adjust($this->cabinet, null, 'set', -100));
    }

    public function test_an_unknown_operation_changes_nothing(): void
    {
        try {
            $this->ledger->adjust($this->cabinet, null, 'multiply', 2);
            $this->fail('An unknown operation must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('multiply', $exception->getMessage());
        }

        $this->assertSame(500, $this->ledger->balance($this->cabinet));
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_the_adjustment_note_is_trimmed_and_capped(): void
    {
        $this->ledger->adjust($this->cabinet, null, 'add', 1, '  Recharge annuelle  ');
        $this->ledger->adjust($this->cabinet, null, 'add', 1, '   ');
        $this->ledger->adjust($this->cabinet, null, 'add', 1, str_repeat('é', 400));
        $this->ledger->adjust($this->cabinet, null, 'add', 1);

        $notes = AiUsage::query()->orderBy('id')->pluck('note')->all();

        $this->assertSame('Recharge annuelle', $notes[0]);
        $this->assertNull($notes[1]);
        $this->assertSame(255, mb_strlen((string) $notes[2]));
        $this->assertNull($notes[3]);
    }
}
