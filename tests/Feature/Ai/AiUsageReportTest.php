<?php

namespace Tests\Feature\Ai;

use App\Enums\AiUsagePeriod;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Services\Ai\AiUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The platform's AI consumption, read from the credit ledger.
 */
class AiUsageReportTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private Cabinet $first;

    private Cabinet $second;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-05 15:00:00');
        $this->first = $this->makeCabinet('Un');
        $this->second = $this->makeCabinet('Deux');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function line(Cabinet $cabinet, string $feature, int $credits, string $at, string $status = AiUsage::STATUS_CHARGED, int $prompt = 0, int $completion = 0): void
    {
        $usage = AiUsage::query()->create([
            'cabinet_id' => $cabinet->getKey(),
            'feature' => $feature,
            'credits' => $credits,
            'balance_after' => 0,
            'status' => $status,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
        ]);
        $usage->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function report(AiUsagePeriod $period = AiUsagePeriod::LAST_7_DAYS): AiUsageReport
    {
        return AiUsageReport::for($period, CarbonImmutable::now());
    }

    public function test_an_empty_ledger_reports_zeros(): void
    {
        $this->assertSame([
            'credits_spent' => 0,
            'calls' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cabinets' => 0,
            'credits_added' => 0,
            'recharges' => 0,
        ], $this->report()->totals());
        $this->assertSame([], $this->report()->byFeature());
        $this->assertSame(array_fill(0, 7, 0), array_values($this->report()->daily()));
    }

    public function test_totals_count_spends_tokens_cabinets_and_recharges(): void
    {
        $this->line($this->first, 'patient_analysis', -5, '2026-10-05 10:00:00', prompt: 1000, completion: 200);
        $this->line($this->first, 'copilot_chat', -1, '2026-10-04 10:00:00', prompt: 300, completion: 50);
        $this->line($this->second, 'ecg_analysis', -4, '2026-10-01 09:00:00', prompt: 2000, completion: 400);
        $this->line($this->first, 'admin_adjustment', 500, '2026-10-02 08:00:00', AiUsage::STATUS_ADJUSTED);
        $this->line($this->second, 'admin_adjustment', -100, '2026-10-02 08:00:00', AiUsage::STATUS_ADJUSTED);

        $this->assertSame([
            'credits_spent' => 10,
            'calls' => 3,
            'prompt_tokens' => 3300,
            'completion_tokens' => 650,
            'cabinets' => 2,
            'credits_added' => 500,
            'recharges' => 1,
        ], $this->report()->totals());
    }

    public function test_lines_outside_the_period_are_ignored(): void
    {
        $this->line($this->first, 'copilot_chat', -1, '2026-09-28 23:59:59');
        $this->line($this->first, 'copilot_chat', -1, '2026-09-29 00:00:00');
        $this->line($this->first, 'copilot_chat', -1, '2026-10-05 15:00:00');
        $this->line($this->first, 'copilot_chat', -1, '2026-10-05 15:00:01');

        $this->assertSame(2, $this->report()->totals()['calls']);
    }

    public function test_daily_lists_every_day_including_quiet_ones(): void
    {
        $this->line($this->first, 'copilot_chat', -1, '2026-10-05 09:00:00');
        $this->line($this->first, 'patient_analysis', -5, '2026-10-05 18:00:00');
        $this->line($this->second, 'exam_suggestions', -2, '2026-10-01 12:00:00');
        $this->line($this->first, 'admin_adjustment', 50, '2026-10-01 12:00:00', AiUsage::STATUS_ADJUSTED);

        CarbonImmutable::setTestNow('2026-10-05 23:00:00');

        $this->assertSame([
            '2026-09-29' => 0,
            '2026-09-30' => 0,
            '2026-10-01' => 2,
            '2026-10-02' => 0,
            '2026-10-03' => 0,
            '2026-10-04' => 0,
            '2026-10-05' => 6,
        ], $this->report()->daily());
    }

    public function test_last_month_daily_covers_the_whole_month(): void
    {
        $daily = $this->report(AiUsagePeriod::LAST_MONTH)->daily();

        $this->assertCount(30, $daily);
        $this->assertSame('2026-09-01', array_key_first($daily));
        $this->assertSame('2026-09-30', array_key_last($daily));
    }

    public function test_by_feature_is_sorted_by_credits_then_calls(): void
    {
        $this->line($this->first, 'copilot_chat', -1, '2026-10-05 09:00:00', prompt: 10, completion: 5);
        $this->line($this->first, 'copilot_chat', -1, '2026-10-05 09:00:00', prompt: 10, completion: 5);
        $this->line($this->first, 'exam_suggestions', -2, '2026-10-05 09:00:00');
        $this->line($this->first, 'patient_analysis', -5, '2026-10-05 09:00:00', prompt: 100, completion: 20);
        $this->line($this->first, 'admin_adjustment', 500, '2026-10-05 09:00:00', AiUsage::STATUS_ADJUSTED);

        $this->assertSame([
            'patient_analysis' => ['credits' => 5, 'calls' => 1, 'tokens' => 120],
            'copilot_chat' => ['credits' => 2, 'calls' => 2, 'tokens' => 30],
            'exam_suggestions' => ['credits' => 2, 'calls' => 1, 'tokens' => 0],
        ], $this->report()->byFeature());
    }

    public function test_the_report_window_comes_from_the_period(): void
    {
        $report = AiUsageReport::for(AiUsagePeriod::THIS_MONTH, CarbonImmutable::parse('2026-10-05 15:00:00'));

        $this->assertSame('2026-10-01 00:00:00', $report->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 15:00:00', $report->until->format('Y-m-d H:i:s'));
    }
}
