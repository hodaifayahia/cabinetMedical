<?php

namespace Tests\Feature\Filament;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Widgets\CabinetGrowth;
use App\Filament\Widgets\LicenceExpiryRadar;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PlatformInsightWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_the_expiry_radar_lists_only_licences_inside_the_renewal_window(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        $expiringSoon = $this->licensedCabinet('Cabinet Échéance', now()->addDays(4));
        $alreadyExpired = $this->licensedCabinet('Cabinet Expiré', now()->subDay());
        $farFuture = $this->licensedCabinet('Cabinet Tranquille', now()->addMonths(6));
        $lifetime = $this->licensedCabinet('Cabinet À vie', null);

        Livewire::actingAs($platformAdmin)
            ->test(LicenceExpiryRadar::class)
            ->assertCanSeeTableRecords([$expiringSoon, $alreadyExpired])
            ->assertCanNotSeeTableRecords([$farFuture, $lifetime]);
    }

    public function test_the_expiry_radar_orders_the_soonest_deadline_first(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        $later = $this->licensedCabinet('Cabinet Plus tard', now()->addDays(20));
        $sooner = $this->licensedCabinet('Cabinet Bientôt', now()->addDay());

        Livewire::actingAs($platformAdmin)
            ->test(LicenceExpiryRadar::class)
            ->assertCanSeeTableRecords([$sooner, $later], inOrder: true);
    }

    public function test_the_growth_chart_renders_twelve_weekly_buckets(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        Cabinet::query()->create([
            'name' => 'Cabinet de cette semaine',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        $widget = new class extends CabinetGrowth
        {
            /** @return array<string, mixed> */
            public function dataForTesting(): array
            {
                return $this->getData();
            }
        };

        $data = $widget->dataForTesting();

        $this->assertCount(12, $data['labels']);
        $this->assertCount(12, $data['datasets'][0]['data']);
        $this->assertSame(1, $data['datasets'][0]['data'][11], 'the current week counts the new sign-up');
        $this->assertSame(1, $data['datasets'][1]['data'][11], 'the current week counts the activation');
    }

    public function test_insight_widgets_reject_non_platform_users(): void
    {
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($cabinetUser);

        $this->assertFalse(CabinetGrowth::canView());
        $this->assertFalse(LicenceExpiryRadar::canView());
    }

    private function licensedCabinet(string $name, mixed $expiresAt): Cabinet
    {
        $license = License::query()->create([
            'license_id' => 'RADAR-'.str()->uuid(),
            'product' => 'Drclick',
            'edition' => 'hosted',
            'plan' => LicensePlan::TRIAL,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'license_id' => $license->getKey(),
        ]);
    }
}
