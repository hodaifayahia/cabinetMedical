<?php

namespace Tests\Feature\Filament;

use App\Enums\AiUsagePeriod;
use App\Enums\CabinetStatus;
use App\Filament\Pages\AiConsumption;
use App\Filament\Resources\AiUsages\Pages\ListAiUsages;
use App\Filament\Widgets\AiCabinetWallets;
use App\Filament\Widgets\AiDailyConsumption;
use App\Filament\Widgets\AiFeatureBreakdown;
use App\Filament\Widgets\AiUsageStats;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI back office: consumption figures read from the credit ledger, and
 * the recharges an operator makes from the page.
 */
final class AiConsumptionPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 15:00:00'));
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_only_platform_administrators_can_open_the_ai_page(): void
    {
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);

        $this->get(AiConsumption::getUrl())->assertRedirect('/admin/login');

        $this->actingAs($cabinetUser)->get(AiConsumption::getUrl())->assertForbidden();

        $this->actingAs($this->admin)
            ->get(AiConsumption::getUrl())
            ->assertOk()
            ->assertSee('Consommation IA')
            ->assertSee('Recharger un cabinet')
            ->assertSee('Portefeuilles des cabinets');
    }

    public function test_the_widgets_are_hidden_from_cabinet_users(): void
    {
        $this->actingAs(User::factory()->create(['is_platform_admin' => false]));

        $this->assertFalse(AiUsageStats::canView());
        $this->assertFalse(AiDailyConsumption::canView());
        $this->assertFalse(AiFeatureBreakdown::canView());
        $this->assertFalse(AiCabinetWallets::canView());
    }

    public function test_the_report_totals_spends_tokens_and_recharges_inside_the_period_only(): void
    {
        $first = $this->cabinet('Cabinet Amrani');
        $second = $this->cabinet('Cabinet Belkacem');

        $this->spend($first, 'patient_analysis', 5, 1200, 300, now()->subDays(2));
        $this->spend($first, 'copilot_chat', 1, 400, 100, now()->subHour());
        $this->spend($second, 'ecg_analysis', 4, 900, 250, now()->subDays(3));
        // Outside the 7-day window: counted for 30 days, not for 7.
        $this->spend($second, 'ecg_analysis', 4, 900, 250, now()->subDays(20));
        $this->adjustment($first, 500, now()->subDay());
        // A deduction is not a recharge.
        $this->adjustment($second, -50, now()->subDay());

        $week = AiUsageReport::for(AiUsagePeriod::LAST_7_DAYS)->totals();

        $this->assertSame(10, $week['credits_spent']);
        $this->assertSame(3, $week['calls']);
        $this->assertSame(2500, $week['prompt_tokens']);
        $this->assertSame(650, $week['completion_tokens']);
        $this->assertSame(2, $week['cabinets']);
        $this->assertSame(500, $week['credits_added']);
        $this->assertSame(1, $week['recharges']);

        $this->assertSame(14, AiUsageReport::for(AiUsagePeriod::LAST_30_DAYS)->totals()['credits_spent']);
    }

    public function test_last_month_stops_at_the_end_of_the_previous_month(): void
    {
        $cabinet = $this->cabinet('Cabinet Chaoui');

        $this->spend($cabinet, 'copilot_chat', 1, 10, 10, CarbonImmutable::parse('2026-08-01 00:00:00'));
        $this->spend($cabinet, 'copilot_chat', 1, 10, 10, CarbonImmutable::parse('2026-08-31 23:59:00'));
        $this->spend($cabinet, 'copilot_chat', 1, 10, 10, CarbonImmutable::parse('2026-09-01 00:00:00'));

        $this->assertSame(2, AiUsageReport::for(AiUsagePeriod::LAST_MONTH)->totals()['credits_spent']);
        $this->assertSame(1, AiUsageReport::for(AiUsagePeriod::THIS_MONTH)->totals()['credits_spent']);
    }

    public function test_the_daily_series_has_every_day_of_the_period_including_quiet_ones(): void
    {
        $cabinet = $this->cabinet('Cabinet Djebbar');

        $this->spend($cabinet, 'patient_analysis', 5, 10, 10, CarbonImmutable::parse('2026-09-25 08:00:00'));
        $this->spend($cabinet, 'copilot_chat', 1, 10, 10, CarbonImmutable::parse('2026-09-25 14:59:00'));
        $this->spend($cabinet, 'ecg_analysis', 4, 10, 10, CarbonImmutable::parse('2026-09-19 00:05:00'));

        $daily = AiUsageReport::for(AiUsagePeriod::LAST_7_DAYS)->daily();

        $this->assertSame([
            '2026-09-19' => 4,
            '2026-09-20' => 0,
            '2026-09-21' => 0,
            '2026-09-22' => 0,
            '2026-09-23' => 0,
            '2026-09-24' => 0,
            '2026-09-25' => 6,
        ], $daily);
    }

    public function test_the_feature_breakdown_puts_the_heaviest_feature_first(): void
    {
        $cabinet = $this->cabinet('Cabinet Ferhat');

        $this->spend($cabinet, 'copilot_chat', 1, 100, 50, now()->subHour());
        $this->spend($cabinet, 'copilot_chat', 1, 100, 50, now()->subHour());
        $this->spend($cabinet, 'patient_analysis', 5, 1000, 200, now()->subHour());

        $features = AiUsageReport::for(AiUsagePeriod::LAST_30_DAYS)->byFeature();

        $this->assertSame(['patient_analysis', 'copilot_chat'], array_keys($features));
        $this->assertSame(['credits' => 2, 'calls' => 2, 'tokens' => 300], $features['copilot_chat']);
    }

    public function test_the_stats_report_period_consumption_and_wallets_to_recharge(): void
    {
        $busy = $this->cabinet('Cabinet Ghali', credits: 20);
        $this->cabinet('Cabinet Hamdi', credits: 0);
        $this->cabinet('Cabinet Idir', credits: 800);

        $this->spend($busy, 'patient_analysis', 5, 1_500_000, 250_000, now()->subDay());

        Livewire::actingAs($this->admin)
            ->test(AiUsageStats::class, ['pageFilters' => ['period' => AiUsagePeriod::LAST_7_DAYS->value]])
            ->assertSee('Crédits consommés')
            ->assertSee('1 appel IA · 7 derniers jours')
            ->assertSee('1,8 M')
            ->assertSee('Entrée 1,5 M · sortie 250 k')
            ->assertSee('Cabinets à recharger')
            ->assertSee('dont 1 à zéro')
            ->assertSee('820');
    }

    public function test_the_consumption_widgets_do_not_poll_the_ledger(): void
    {
        foreach ([AiUsageStats::class, AiDailyConsumption::class, AiFeatureBreakdown::class] as $widget) {
            Livewire::actingAs($this->admin)
                ->test($widget, ['pageFilters' => ['period' => AiUsagePeriod::LAST_7_DAYS->value]])
                ->assertDontSeeHtml('wire:poll');
        }
    }

    public function test_the_wallets_table_ranks_the_heaviest_consumers_of_the_period_first(): void
    {
        $light = $this->cabinet('Cabinet Léger');
        $heavy = $this->cabinet('Cabinet Lourd');
        $idle = $this->cabinet('Cabinet Inactif');

        $this->spend($light, 'copilot_chat', 1, 10, 10, now()->subDay());
        $this->spend($heavy, 'patient_analysis', 5, 10, 10, now()->subDay());
        $this->spend($heavy, 'patient_analysis', 5, 10, 10, now()->subDay());
        // Heavy last month, idle this week: must not lead the 7-day ranking.
        $this->spend($idle, 'patient_analysis', 5, 10, 10, now()->subDays(20));
        $this->spend($idle, 'patient_analysis', 5, 10, 10, now()->subDays(20));
        $this->spend($idle, 'patient_analysis', 5, 10, 10, now()->subDays(20));

        Livewire::actingAs($this->admin)
            ->test(AiCabinetWallets::class, ['pageFilters' => ['period' => AiUsagePeriod::LAST_7_DAYS->value]])
            ->assertCanSeeTableRecords([$heavy, $light, $idle], inOrder: true)
            ->filterTable('used_in_period')
            ->assertCanSeeTableRecords([$heavy, $light])
            ->assertCanNotSeeTableRecords([$idle]);
    }

    public function test_the_low_balance_filter_lists_enabled_cabinets_under_the_threshold(): void
    {
        $low = $this->cabinet('Cabinet Bas', credits: 12);
        $full = $this->cabinet('Cabinet Plein', credits: 400);
        $disabled = $this->cabinet('Cabinet Coupé', credits: 0, enabled: false);

        Livewire::actingAs($this->admin)
            ->test(AiCabinetWallets::class)
            ->filterTable('low_balance')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$full, $disabled]);
    }

    public function test_an_operator_recharges_a_cabinet_from_the_page_header(): void
    {
        $cabinet = $this->cabinet('Cabinet Kaci', credits: 30);

        Livewire::actingAs($this->admin)
            ->test(AiConsumption::class)
            ->callAction('rechargeAiCredits', data: [
                'cabinet_id' => $cabinet->getKey(),
                'operation' => 'add',
                'amount' => 250,
                'note' => 'Recharge payée le 25/09',
            ])
            ->assertHasNoActionErrors()
            ->assertDispatched('ai-credits-updated');

        $this->assertSame(280, $cabinet->fresh()->ai_credits);
        $this->assertTrue($cabinet->fresh()->ai_enabled);

        $line = AiUsage::query()->sole();
        $this->assertSame(250, $line->credits);
        $this->assertSame(280, $line->balance_after);
        $this->assertSame(AiUsage::STATUS_ADJUSTED, $line->status);
        $this->assertSame($this->admin->getKey(), $line->user_id);
        $this->assertSame('Recharge payée le 25/09', $line->note);

        $this->assertTrue(AuditLog::query()->where('action', 'admin.cabinet_ai_credits_updated')->exists());
    }

    public function test_the_header_recharge_requires_a_cabinet(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AiConsumption::class)
            ->callAction('rechargeAiCredits', data: [
                'cabinet_id' => null,
                'operation' => 'add',
                'amount' => 100,
            ])
            ->assertHasActionErrors(['cabinet_id' => 'required']);

        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_the_ledger_page_offers_the_same_recharge(): void
    {
        $cabinet = $this->cabinet('Cabinet Lamri', credits: 0);

        Livewire::actingAs($this->admin)
            ->test(ListAiUsages::class)
            ->callAction('rechargeAiCredits', data: [
                'cabinet_id' => $cabinet->getKey(),
                'operation' => 'set',
                'amount' => 100,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(100, $cabinet->fresh()->ai_credits);
    }

    public function test_a_row_recharge_can_also_switch_the_assistant_off(): void
    {
        $cabinet = $this->cabinet('Cabinet Mansouri', credits: 40);

        Livewire::actingAs($this->admin)
            ->test(AiCabinetWallets::class)
            ->callTableAction('manageAiCredits', $cabinet, [
                'operation' => 'add',
                'amount' => 60,
                'ai_enabled' => false,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(100, $cabinet->fresh()->ai_credits);
        $this->assertFalse($cabinet->fresh()->ai_enabled);
    }

    public function test_a_bulk_recharge_adds_the_same_amount_to_every_selected_cabinet(): void
    {
        $first = $this->cabinet('Cabinet Nait', credits: 10);
        $second = $this->cabinet('Cabinet Ouali', credits: 0);
        $untouched = $this->cabinet('Cabinet Rahmani', credits: 70);

        Livewire::actingAs($this->admin)
            ->test(AiCabinetWallets::class)
            ->callTableBulkAction('bulkRechargeAiCredits', [$first, $second], [
                'amount' => 100,
                'note' => 'Offre de rentrée',
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(110, $first->fresh()->ai_credits);
        $this->assertSame(100, $second->fresh()->ai_credits);
        $this->assertSame(70, $untouched->fresh()->ai_credits);
        $this->assertSame(2, AiUsage::query()->where('status', AiUsage::STATUS_ADJUSTED)->where('credits', 100)->count());
    }

    private function cabinet(string $name, int $credits = 500, bool $enabled = true): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        Cabinet::query()->whereKey($cabinet->getKey())->update([
            'ai_credits' => $credits,
            'ai_enabled' => $enabled,
        ]);

        return $cabinet->fresh();
    }

    private function spend(Cabinet $cabinet, string $feature, int $credits, int $promptTokens, int $completionTokens, CarbonImmutable $at): void
    {
        $this->line($cabinet, [
            'feature' => $feature,
            'credits' => -$credits,
            'status' => AiUsage::STATUS_CHARGED,
            'model' => 'qwen-test',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ], $at);
    }

    private function adjustment(Cabinet $cabinet, int $credits, CarbonImmutable $at): void
    {
        $this->line($cabinet, [
            'feature' => 'admin_adjustment',
            'credits' => $credits,
            'status' => AiUsage::STATUS_ADJUSTED,
        ], $at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(Cabinet $cabinet, array $attributes, CarbonImmutable $at): void
    {
        $line = new AiUsage([
            'cabinet_id' => $cabinet->getKey(),
            'balance_after' => $cabinet->ai_credits,
            ...$attributes,
        ]);
        $line->created_at = $at;
        $line->updated_at = $at;
        $line->save();
    }
}
