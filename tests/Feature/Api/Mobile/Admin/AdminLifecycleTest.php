<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Activating and suspending a clinic from the admin app. The transitions are
 * delegated to CabinetFulfillmentService, so what is asserted here is the API
 * contract around it: the resulting state, the 409 reasons for a transition
 * that cannot happen, the audit trail, and the fact that suspending really
 * does take the clinic out of public discovery.
 */
class AdminLifecycleTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_pending_clinic_is_activated(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet En Attente',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 16,
        ]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$cabinet->getKey().'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.license.status', 'active');

        $this->assertTrue($cabinet->refresh()->isActive());
        $this->assertNotNull($cabinet->activated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.cabinet_activated',
            'subject_id' => (string) $cabinet->getKey(),
        ]);
    }

    public function test_activating_an_active_clinic_is_a_conflict(): void
    {
        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/activate')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_active');
    }

    public function test_an_active_clinic_is_suspended_and_leaves_public_discovery(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = (int) $clinic['cabinet']->getKey();

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertTrue($clinic['cabinet']->refresh()->isSuspended());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.cabinet_suspended',
            'subject_id' => (string) $cabinetId,
        ]);

        // The public profile is untouched — it is the cabinet status that
        // removes the clinic from discovery.
        $this->assertTrue(
            CabinetPublicProfile::withoutCabinetScope()
                ->where('cabinet_id', $cabinetId)
                ->where('is_listed', true)
                ->exists(),
        );

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/clinics/'.$cabinetId)->assertNotFound();
    }

    public function test_suspending_a_suspended_clinic_is_a_conflict(): void
    {
        $clinic = $this->makeListedClinic();
        $clinic['cabinet']->forceFill(['status' => CabinetStatus::SUSPENDED])->save();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/suspend')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_suspended');
    }

    public function test_suspending_a_pending_clinic_is_a_conflict(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet En Attente',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 16,
        ]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$cabinet->getKey().'/suspend')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'cabinet_not_active');

        $this->assertTrue($cabinet->refresh()->isPending());
    }

    public function test_a_suspended_clinic_is_restored_by_activate(): void
    {
        $clinic = $this->makeListedClinic();
        $clinic['cabinet']->forceFill(['status' => CabinetStatus::SUSPENDED])->save();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($clinic['cabinet']->refresh()->isActive());
        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_directory_listing_is_toggled_from_the_admin_app(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = (int) $clinic['cabinet']->getKey();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->patchJson('/api/v1/admin/cabinets/'.$cabinetId.'/listing', ['is_listed' => false])
            ->assertOk()
            ->assertJsonPath('data.is_listed', false);

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson('/api/v1/admin/cabinets/'.$cabinetId.'/listing', ['is_listed' => true])
            ->assertOk()
            ->assertJsonPath('data.is_listed', true);

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.cabinet_listing_updated',
            'subject_id' => (string) $cabinetId,
        ]);
    }

    public function test_an_unknown_clinic_is_a_404(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/cabinets/999999')->assertNotFound();
        $this->postJson('/api/v1/admin/cabinets/999999/activate')->assertNotFound();
    }
}
