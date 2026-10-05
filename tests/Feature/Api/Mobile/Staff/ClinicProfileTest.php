<?php

namespace Tests\Feature\Api\Mobile\Staff;

use App\Enums\RoleName;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Management of the cabinet's public directory listing from the staff
 * mobile app. Writing requires configuration.branding.manage; the is_listed
 * flag is what makes the clinic visible in public discovery.
 */
class ClinicProfileTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_show_returns_defaults_when_the_cabinet_was_never_listed(): void
    {
        $clinic = $this->makeListedClinic();
        CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $clinic['cabinet']->getKey())
            ->delete();

        Sanctum::actingAs($clinic['doctorUser']);

        $response = $this->getJson('/api/v1/mobile/clinic-profile')
            ->assertOk()
            ->assertJsonPath('data.is_listed', false)
            ->assertJsonPath('data.clinic.id', $clinic['cabinet']->getKey())
            ->assertJsonPath('data.clinic.name', $clinic['cabinet']->name);

        $this->assertCount(7, $response->json('data.working_hours'));
    }

    public function test_the_doctor_updates_the_public_profile(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'is_listed' => true,
            'about' => 'Cabinet de médecine générale au centre-ville.',
            'address' => '12 rue Didouche Mourad, Alger',
            'phones' => ['0551234567', '0661234567'],
            'latitude' => 36.7538,
            'longitude' => 3.0588,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_listed', true)
            ->assertJsonPath('data.about', 'Cabinet de médecine générale au centre-ville.')
            ->assertJsonPath('data.phones.0', '0551234567')
            ->assertJsonPath('data.clinic.name', $clinic['cabinet']->name);

        $this->assertDatabaseHas('cabinet_public_profiles', [
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'is_listed' => true,
            'address' => '12 rue Didouche Mourad, Alger',
        ]);
    }

    public function test_unlisting_hides_the_clinic_from_public_discovery(): void
    {
        $clinic = $this->makeListedClinic();
        $doctorId = $clinic['doctor']->getKey();

        $listedIds = collect($this->getJson('/api/v1/doctors')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($listedIds->contains($doctorId));

        Sanctum::actingAs($clinic['doctorUser']);
        $this->putJson('/api/v1/mobile/clinic-profile', ['is_listed' => false])
            ->assertOk()
            ->assertJsonPath('data.is_listed', false);

        // Discovery is public — drop the staff token before re-checking.
        $this->app['auth']->forgetGuards();

        $remainingIds = collect($this->getJson('/api/v1/doctors')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($remainingIds->contains($doctorId));
    }

    public function test_an_assistant_may_view_but_not_update(): void
    {
        $clinic = $this->makeListedClinic();
        $assistant = User::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        Sanctum::actingAs($assistant);

        $this->getJson('/api/v1/mobile/clinic-profile')->assertOk();

        $this->putJson('/api/v1/mobile/clinic-profile', ['is_listed' => false])
            ->assertStatus(403);

        $this->assertDatabaseHas('cabinet_public_profiles', [
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'is_listed' => true,
        ]);
    }

    public function test_a_patient_token_is_rejected(): void
    {
        Sanctum::actingAs($this->makePatientUser());

        $this->getJson('/api/v1/mobile/clinic-profile')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_token_forbidden');
    }

    public function test_phone_and_photo_limits_are_validated(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'phones' => ['12345'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phones.0');

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'phones' => ['0551111111', '0552222222', '0553333333', '0554444444'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phones');

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'photos' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg', 'f.jpg', 'g.jpg'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');
    }
}
