<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiConsumption;
use App\Filament\Pages\SoftwareVersion;
use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\Cabinets\CabinetResource;
use App\Filament\Resources\DesktopDownloadLeads\DesktopDownloadLeadResource;
use App\Filament\Resources\LandingSections\LandingSectionResource;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\LicenseTypes\LicenseTypeResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page the sidebar can reach must render for a platform admin. Panel
 * screens are wired up through a lot of closures that only fail at render
 * time, so a plain GET over the whole navigation is worth its runtime.
 */
final class PlatformNavigationSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_every_navigable_page_renders_for_a_platform_admin(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        $urls = [
            '/admin',
            CabinetResource::getUrl('index'),
            AiConsumption::getUrl(),
            AiUsageResource::getUrl('index'),
            DesktopDownloadLeadResource::getUrl('index'),
            ActivationKeyResource::getUrl('index'),
            LicenseResource::getUrl('index'),
            LicenseResource::getUrl('create'),
            LicenseTypeResource::getUrl('index'),
            SoftwareVersion::getUrl(),
            LandingSectionResource::getUrl('index'),
            UserResource::getUrl('index'),
            UserResource::getUrl('create'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_sidebar_shows_the_expected_groups_and_no_removed_ones(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->actingAs($platformAdmin)->get('/admin')->assertOk();

        foreach ([
            'Clients',
            'Intelligence artificielle',
            'Consommation IA',
            'Journal des crédits',
            'Licences &amp; activations',
            'Clés d’activation',
            'Utilisateurs',
            'Console plateforme',
        ] as $expected) {
            $response->assertSee($expected, escape: false);
        }

        foreach ([
            'Dossiers patients',
            'Catalogue',
            'Journal &amp; activité',
            'Appareils',
            'Activité &amp; connexions',
            'Événements système',
        ] as $removed) {
            $response->assertDontSee($removed, escape: false);
        }
    }
}
