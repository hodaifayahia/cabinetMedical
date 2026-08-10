<?php

namespace Tests\Feature\Landing;

use App\Filament\Resources\Acts\ActResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\BilanTypes\BilanTypeResource;
use App\Filament\Resources\ConsultationFees\ConsultationFeeResource;
use App\Filament\Resources\Exams\ExamResource;
use App\Filament\Resources\LandingSections\LandingSectionResource;
use App\Filament\Resources\Medications\MedicationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Practitioners\PractitionerResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\LandingSection;
use App\Models\User;
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

    public function test_platform_admin_can_access_landing_content_and_removed_resources_stay_out_of_navigation(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin);

        $this->assertTrue(LandingSectionResource::canAccess());
        $this->assertFalse(PatientResource::shouldRegisterNavigation());
        $this->assertFalse(AuditLogResource::shouldRegisterNavigation());
        $this->assertFalse(RoleResource::shouldRegisterNavigation());
        $this->assertFalse(MedicationResource::shouldRegisterNavigation());
        $this->assertFalse(ActResource::shouldRegisterNavigation());
        $this->assertFalse(ExamResource::shouldRegisterNavigation());
        $this->assertFalse(BilanTypeResource::shouldRegisterNavigation());
        $this->assertFalse(ConsultationFeeResource::shouldRegisterNavigation());
        $this->assertFalse(PaymentMethodResource::shouldRegisterNavigation());
        $this->assertFalse(PractitionerResource::shouldRegisterNavigation());
    }
}
