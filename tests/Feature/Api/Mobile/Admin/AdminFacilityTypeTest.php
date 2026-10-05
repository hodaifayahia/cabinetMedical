<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\FacilityType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Facility types decide which search tabs the patient app has: switching one
 * off removes its tab and makes its cabinets unreachable from the public API,
 * the same way a region outside coverage is.
 */
class AdminFacilityTypeTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function only_a_platform_admin_may_read_or_change_facility_types(): void
    {
        $this->getJson('/api/v1/admin/facility-types')->assertUnauthorized();

        foreach ([$this->makePatientUser(), $this->makeListedClinic()['doctorUser']] as $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/admin/facility-types')
                ->assertForbidden();

            $this->actingAs($user)
                ->patchJson('/api/v1/admin/facility-types/radiology', ['is_active' => false])
                ->assertForbidden();
        }
    }

    #[Test]
    public function every_type_ships_enabled(): void
    {
        $this->getJson('/api/v1/facility-types')
            ->assertOk()
            ->assertJsonPath('data.*.value', ['doctor', 'clinic', 'radiology'])
            ->assertJsonPath('data.2.label_ar', FacilityType::RADIOLOGY->labelAr());

        $rows = $this->actingAs($this->makePlatformAdmin())
            ->getJson('/api/v1/admin/facility-types')
            ->assertOk()
            ->json('data');

        $this->assertSame([true, true, true], array_column($rows, 'is_active'));
    }

    #[Test]
    public function the_admin_list_reports_what_switching_a_type_off_would_hide(): void
    {
        $this->makeListedClinic();
        $imaging = $this->makeListedClinic();
        $imaging['cabinet']->forceFill(['facility_type' => FacilityType::RADIOLOGY])->save();

        $rows = collect($this->actingAs($this->makePlatformAdmin())
            ->getJson('/api/v1/admin/facility-types')
            ->json('data'))
            ->keyBy('value');

        $this->assertSame(1, $rows['doctor']['clinics']);
        $this->assertSame(0, $rows['clinic']['clinics']);
        $this->assertSame(1, $rows['radiology']['clinics']);
    }

    #[Test]
    public function a_disabled_type_disappears_from_the_tabs_and_from_discovery(): void
    {
        $imaging = $this->makeListedClinic();
        $imaging['cabinet']->forceFill(['facility_type' => FacilityType::RADIOLOGY])->save();
        $doctor = $this->makeListedClinic();

        $this->assertCount(2, $this->getJson('/api/v1/doctors')->json('data'));

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson('/api/v1/admin/facility-types/radiology', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.value', 'radiology')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/facility-types')
            ->assertOk()
            ->assertJsonPath('data.*.value', ['doctor', 'clinic']);

        // Unfiltered search drops it; the explicit tab finds nothing.
        $results = $this->getJson('/api/v1/doctors')->json('data');
        $this->assertCount(1, $results);
        $this->assertSame($doctor['doctor']->getKey(), $results[0]['id']);
        $this->assertSame([], $this->getJson('/api/v1/doctors?facility_type=radiology')->json('data'));

        // Deep links are as dead as a missing clinic.
        $imagingCabinet = $imaging['cabinet']->getKey();
        $imagingDoctor = $imaging['doctor']->getKey();
        $this->getJson("/api/v1/clinics/{$imagingCabinet}")->assertNotFound();
        $this->getJson("/api/v1/doctors/{$imagingDoctor}/availability/month?year=2031&month=1")->assertNotFound();

        $this->actingAs($this->makePatientUser())
            ->postJson('/api/v1/my/appointments', [
                'doctor_id' => $imagingDoctor,
                'starts_at' => '2031-01-06T09:00:00+01:00',
            ])
            ->assertNotFound();

        // Switching it back on restores everything.
        $this->actingAs($this->makePlatformAdmin())
            ->patchJson('/api/v1/admin/facility-types/radiology', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertCount(2, $this->getJson('/api/v1/doctors')->json('data'));
        $this->getJson("/api/v1/clinics/{$imagingCabinet}")->assertOk();
    }

    #[Test]
    public function the_last_enabled_type_cannot_be_switched_off(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->patchJson('/api/v1/admin/facility-types/clinic', ['is_active' => false])->assertOk();
        $this->actingAs($admin)->patchJson('/api/v1/admin/facility-types/radiology', ['is_active' => false])->assertOk();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/facility-types/doctor', ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->getJson('/api/v1/facility-types')->assertJsonPath('data.*.value', ['doctor']);
    }

    #[Test]
    public function every_change_is_audited(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/facility-types/clinic', ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.facility_type_updated',
            'user_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function an_unknown_type_is_a_404_and_the_flag_is_required(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/facility-types/pharmacy', ['is_active' => false])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/facility-types/clinic', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }
}
