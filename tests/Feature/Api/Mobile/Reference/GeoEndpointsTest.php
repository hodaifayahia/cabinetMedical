<?php

namespace Tests\Feature\Api\Mobile\Reference;

use App\Models\Baladiya;
use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public geographic reference endpoints (no authentication required).
 */
class GeoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wilayas_are_listed_ordered_by_code(): void
    {
        Wilaya::factory()->create(['code' => 31, 'name_fr' => 'Oran', 'name_ar' => 'وهران']);
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);

        $this->getJson('/api/v1/wilayas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 16)
            ->assertJsonPath('data.0.name_fr', 'Alger')
            ->assertJsonPath('data.0.name_ar', 'الجزائر')
            ->assertJsonPath('data.1.code', 31)
            ->assertJsonPath('data.1.name_fr', 'Oran');
    }

    public function test_baladiyas_are_scoped_to_their_wilaya_and_ordered_by_french_name(): void
    {
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);
        Wilaya::factory()->create(['code' => 31, 'name_fr' => 'Oran', 'name_ar' => 'وهران']);

        Baladiya::factory()->create(['wilaya_code' => 16, 'name_fr' => 'Hydra', 'name_ar' => 'حيدرة']);
        Baladiya::factory()->create(['wilaya_code' => 16, 'name_fr' => 'Bab El Oued', 'name_ar' => 'باب الوادي']);
        Baladiya::factory()->create(['wilaya_code' => 31, 'name_fr' => 'Es Senia', 'name_ar' => 'السانية']);

        $this->getJson('/api/v1/wilayas/16/baladiyas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name_fr', 'Bab El Oued')
            ->assertJsonPath('data.0.wilaya_code', 16)
            ->assertJsonPath('data.1.name_fr', 'Hydra')
            ->assertJsonMissing(['name_fr' => 'Es Senia']);
    }

    public function test_baladiyas_of_an_unknown_wilaya_return_404(): void
    {
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);

        $this->getJson('/api/v1/wilayas/57/baladiyas')->assertNotFound();
    }

    public function test_specialties_expose_french_and_arabic_labels(): void
    {
        $this->getJson('/api/v1/specialties')
            ->assertOk()
            ->assertJsonCount(21, 'data')
            ->assertJsonStructure(['data' => [['code', 'label_fr', 'label_ar']]])
            ->assertJsonFragment([
                'code' => 'cardiology',
                'label_fr' => 'Cardiologie',
                'label_ar' => 'أمراض القلب',
            ]);
    }
}
