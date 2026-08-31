<?php

namespace Tests\Feature\Landing;

use App\Filament\Resources\LandingSettings\LandingSettingResource;
use App\Models\LandingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingSettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_exposes_landing_settings_for_the_frontend_overrides(): void
    {
        LandingSetting::query()->create([
            'key' => 'contact_phone',
            'locale' => LandingSetting::ALL_LOCALES,
            'value' => '+213 (0) 21 00 00 00',
        ]);
        LandingSetting::query()->create([
            'key' => 'requirements_title',
            'locale' => 'fr',
            'value' => 'Fonctionne sur vos postes actuels',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('landingSettings', 2)
                ->where('landingSettings.0.key', 'contact_phone')
                ->where('landingSettings.0.locale', LandingSetting::ALL_LOCALES)
                ->where('landingSettings.0.value', '+213 (0) 21 00 00 00')
                ->where('landingSettings.1.key', 'requirements_title')
                ->where('landingSettings.1.locale', 'fr'),
            );
    }

    public function test_landing_settings_are_unique_per_key_and_locale(): void
    {
        LandingSetting::query()->create([
            'key' => 'contact_hours',
            'locale' => 'ar',
            'value' => 'من الأحد إلى الخميس، 8:00 – 16:00',
        ]);

        // The same key may exist for another locale…
        LandingSetting::query()->create([
            'key' => 'contact_hours',
            'locale' => 'fr',
            'value' => 'Dimanche à jeudi, 8h00 – 16h00',
        ]);

        // …but not twice for the same locale.
        $this->expectException(\Illuminate\Database\QueryException::class);

        LandingSetting::query()->create([
            'key' => 'contact_hours',
            'locale' => 'ar',
            'value' => 'doublon',
        ]);
    }

    public function test_only_platform_admins_can_access_landing_settings(): void
    {
        $member = User::factory()->create(['is_platform_admin' => false]);
        $this->actingAs($member);
        $this->assertFalse(LandingSettingResource::canAccess());

        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        $this->assertTrue(LandingSettingResource::canAccess());
    }
}
