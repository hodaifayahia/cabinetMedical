<?php

namespace Tests\Feature\Filament;

use App\Enums\LicensePlan;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\Licenses\Pages\ListLicenses;
use App\Models\License;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class LocalLicenseResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_platform_admin_can_open_local_licenses_page_when_no_local_license_exists(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin);

        $this->assertTrue(LicenseResource::canAccess());

        $this->get(LicenseResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Licences locales')
            ->assertSee('Créer une licence locale')
            ->assertSee('Générer un code client')
            ->assertSee('Aucune licence locale')
            ->assertSee('Cette liste affiche uniquement les licences locales signées.');

        Livewire::actingAs($platformAdmin)
            ->test(ListLicenses::class)
            ->assertSuccessful();
    }

    public function test_platform_admin_can_see_existing_non_hosted_licenses_even_with_plan_metadata(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $localLicense = License::query()->create([
            'license_id' => 'LOCAL-PLAN-001',
            'product' => 'ClickDZ Doctor',
            'edition' => 'professional',
            'plan' => LicensePlan::LIFETIME,
            'customer_id' => 'Cabinet local',
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->actingAs($platformAdmin);

        $this->get(LicenseResource::getUrl('index'))
            ->assertOk()
            ->assertSee('LOCAL-PLAN-001')
            ->assertSee('Cabinet local');

        Livewire::actingAs($platformAdmin)
            ->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$localLicense]);
    }
}