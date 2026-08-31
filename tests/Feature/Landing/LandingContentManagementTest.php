<?php

namespace Tests\Feature\Landing;

use App\Filament\Resources\LandingSections\LandingSectionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\LandingSection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingContentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_exposes_only_published_landing_sections(): void
    {
        LandingSection::query()->create([
            'locale' => 'fr',
            'slug' => 'securite',
            'section_type' => 'feature',
            'title' => 'Vos données protégées',
            'body' => 'Un contenu géré depuis le panneau.',
            'items' => [['title' => 'Chiffrement', 'body' => 'Toujours sécurisé.']],
            'sort_order' => 10,
            'is_published' => true,
        ]);
        LandingSection::query()->create([
            'locale' => 'fr',
            'slug' => 'brouillon',
            'title' => 'Brouillon',
            'is_published' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('landingSections', 1)
                ->where('landingSections.0.slug', 'securite')
                ->where('landingSections.0.items.0.title', 'Chiffrement'),
            );
    }

    public function test_platform_admin_can_access_landing_content(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin);

        $this->assertTrue(LandingSectionResource::canAccess());
        $this->assertFalse(RoleResource::shouldRegisterNavigation());
    }

    /**
     * Cabinet-local referentials and per-cabinet dossiers were removed from
     * the platform console: their data lives in each cabinet's own
     * installation, so this panel could never load it.
     */
    public function test_cabinet_local_resources_are_no_longer_routable(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin);

        foreach ([
            '/admin/patients',
            '/admin/acts',
            '/admin/medications',
            '/admin/exams',
            '/admin/bilan-types',
            '/admin/consultation-fees',
            '/admin/payment-methods',
            '/admin/practitioners',
            '/admin/devices',
            '/admin/license-activations',
            '/admin/audit-logs',
            '/admin/application-events',
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_removed_resource_classes_are_not_registered_with_the_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $resources = Filament::getPanel('admin')->getResources();

        foreach ($resources as $resource) {
            $this->assertStringNotContainsString('Filament\\Resources\\Patients', $resource);
            $this->assertStringNotContainsString('Filament\\Resources\\Devices', $resource);
            $this->assertStringNotContainsString('Filament\\Resources\\AuditLogs', $resource);
            $this->assertStringNotContainsString('Filament\\Resources\\ApplicationEvents', $resource);
            $this->assertStringNotContainsString('Filament\\Resources\\LicenseActivations', $resource);
        }
    }
}
