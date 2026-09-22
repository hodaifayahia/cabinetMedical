<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\FacilityType;
use App\Models\Baladiya;
use App\Models\Wilaya;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Coverage decides where the patient app can search at all: a deactivated
 * wilaya disappears from the reference lists AND takes its clinics out of
 * discovery, however complete those clinics' own profiles are.
 */
class AdminCoverageTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function only_a_platform_admin_may_read_or_change_coverage(): void
    {
        Wilaya::factory()->create(['code' => 16]);

        // Guest first: actingAs() persists for the rest of the test, so this
        // has to run before any of them authenticates.
        $this->getJson('/api/v1/admin/coverage/wilayas')->assertUnauthorized();

        foreach ([$this->makePatientUser(), $this->makeListedClinic()['doctorUser']] as $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/admin/coverage/wilayas')
                ->assertForbidden()
                ->assertJsonPath('reason', 'platform_admin_required');

            $this->actingAs($user)
                ->patchJson('/api/v1/admin/coverage/wilayas/16', ['is_active' => false])
                ->assertForbidden();
        }
    }

    #[Test]
    public function deactivating_a_wilaya_removes_it_from_the_public_reference_lists(): void
    {
        $clinic = $this->makeListedClinic();
        $wilayaCode = (int) $clinic['cabinet']->wilaya_code;

        $this->getJson('/api/v1/wilayas')
            ->assertOk()
            ->assertJsonFragment(['code' => $wilayaCode]);

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/coverage/wilayas/{$wilayaCode}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $codes = collect($this->getJson('/api/v1/wilayas')->json('data'))->pluck('code');
        $this->assertNotContains($wilayaCode, $codes);

        // Indistinguishable from a wilaya that does not exist.
        $this->getJson("/api/v1/wilayas/{$wilayaCode}/baladiyas")->assertNotFound();
    }

    #[Test]
    public function a_clinic_in_a_deactivated_wilaya_disappears_from_discovery(): void
    {
        $clinic = $this->makeListedClinic();
        $wilayaCode = (int) $clinic['cabinet']->wilaya_code;

        $this->assertCount(1, $this->getJson('/api/v1/doctors')->json('data'));

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/coverage/wilayas/{$wilayaCode}", ['is_active' => false])
            ->assertOk();

        $this->assertSame([], $this->getJson('/api/v1/doctors')->json('data'));

        // ...and comes back when coverage is restored.
        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/coverage/wilayas/{$wilayaCode}", ['is_active' => true])
            ->assertOk();

        $this->assertCount(1, $this->getJson('/api/v1/doctors')->json('data'));
    }

    #[Test]
    public function deactivating_a_single_baladiya_hides_only_the_clinics_inside_it(): void
    {
        $clinic = $this->makeListedClinic();
        $baladiyaId = $clinic['baladiya']->getKey();

        $this->assertCount(1, $this->getJson('/api/v1/doctors')->json('data'));

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/coverage/baladiyas/{$baladiyaId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame([], $this->getJson('/api/v1/doctors')->json('data'));

        // The wilaya itself is untouched, so it still offers its other communes.
        $codes = collect($this->getJson('/api/v1/wilayas')->json('data'))->pluck('code');
        $this->assertContains((int) $clinic['cabinet']->wilaya_code, $codes);
    }

    #[Test]
    public function cascading_switches_every_commune_with_the_wilaya(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => 31, 'is_active' => true]);
        Baladiya::factory()->count(3)->create(['wilaya_code' => $wilaya->code, 'is_active' => true]);

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/coverage/wilayas/{$wilaya->code}", [
                'is_active' => false,
                'cascade' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.cascaded', true);

        $this->assertSame(
            0,
            Baladiya::query()->where('wilaya_code', $wilaya->code)->where('is_active', true)->count(),
        );
    }

    #[Test]
    public function the_coverage_list_reports_what_switching_a_region_off_would_hide(): void
    {
        $clinic = $this->makeListedClinic();
        $wilayaCode = (int) $clinic['cabinet']->wilaya_code;

        $rows = $this->actingAs($this->makePlatformAdmin())
            ->getJson('/api/v1/admin/coverage/wilayas')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('code', $wilayaCode);

        $this->assertNotNull($row);
        $this->assertTrue($row['is_active']);
        $this->assertSame(1, $row['clinics'], 'the admin must see the clinics a region holds');
        $this->assertArrayHasKey('baladiyas_active', $row);
    }

    #[Test]
    public function an_unknown_region_is_a_404_rather_than_a_silent_no_op(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/coverage/wilayas/99', ['is_active' => false])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/coverage/baladiyas/999999', ['is_active' => false])
            ->assertNotFound();
    }

    #[Test]
    public function the_facility_type_drives_the_search_tabs(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = $clinic['cabinet']->getKey();

        // Ships as a doctor's practice.
        $this->assertCount(1, $this->getJson('/api/v1/doctors?facility_type=doctor')->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/doctors?facility_type=radiology')->json('data'));

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/cabinets/{$cabinetId}/facility-type", [
                'facility_type' => FacilityType::RADIOLOGY->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.facility_type', 'radiology');

        // It moves between tabs immediately.
        $this->assertSame([], $this->getJson('/api/v1/doctors?facility_type=doctor')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/doctors?facility_type=radiology')->json('data'));

        // An unfiltered search still finds it.
        $this->assertCount(1, $this->getJson('/api/v1/doctors')->json('data'));
    }

    #[Test]
    public function an_unknown_facility_type_is_rejected(): void
    {
        $clinic = $this->makeListedClinic();

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/cabinets/{$clinic['cabinet']->getKey()}/facility-type", [
                'facility_type' => 'pharmacy',
            ])
            ->assertStatus(422);

        $this->getJson('/api/v1/doctors?facility_type=pharmacy')->assertStatus(422);
    }
}
