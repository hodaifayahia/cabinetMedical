<?php

namespace Tests\Feature\Api\Mobile;

use App\Enums\FamilyMemberStatus;
use App\Enums\RoleName;
use App\Models\FamilyMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Foundation wiring: migrations, roles, route file, middleware boundaries.
 * The endpoint behaviours themselves are covered by the per-module suites.
 */
class FoundationBootTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_mobile_routes_are_registered_inside_the_v1_prefix(): void
    {
        // The stub controllers answer 501 until the modules land; the route
        // must exist either way (404 would mean the file is not loaded).
        $response = $this->getJson('/api/v1/wilayas');

        $this->assertNotSame(404, $response->status());
    }

    public function test_patient_token_is_rejected_on_every_staff_endpoint(): void
    {
        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        foreach ([
            ['getJson', '/api/v1/patients'],
            ['getJson', '/api/v1/appointments'],
            ['getJson', '/api/v1/schedule'],
            ['getJson', '/api/v1/sync/appointments'],
            ['putJson', '/api/v1/mobile/schedule'],
            ['getJson', '/api/v1/mobile/appointments/today'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)
                ->assertStatus(403)
                ->assertJsonPath('reason', 'patient_token_forbidden');
        }
    }

    public function test_staff_token_is_rejected_on_patient_only_endpoints(): void
    {
        ['doctorUser' => $doctorUser] = $this->makeListedClinic();
        Sanctum::actingAs($doctorUser);

        $this->getJson('/api/v1/my/profile')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_role_required');

        $this->getJson('/api/v1/family-members')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_role_required');
    }

    public function test_mobile_role_precedence(): void
    {
        $patient = $this->makePatientUser();
        ['doctorUser' => $doctorUser, 'cabinet' => $cabinet] = $this->makeListedClinic();

        $assistant = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->assertSame('patient', $patient->mobileRole());
        $this->assertTrue($patient->isMobilePatient());
        $this->assertSame('doctor', $doctorUser->mobileRole());
        $this->assertSame('reception', $assistant->mobileRole());
        $this->assertSame('admin', $admin->mobileRole());
        $this->assertFalse($doctorUser->isMobilePatient());
    }

    public function test_patient_account_carries_a_profile_and_zero_staff_permissions(): void
    {
        $patient = $this->makePatientUser();

        $this->assertNotNull($patient->patientProfile);
        $this->assertNull($patient->cabinet_id);
        $this->assertCount(0, $patient->getAllPermissions());
    }

    public function test_family_member_booking_usability_rules(): void
    {
        $owner = $this->makePatientUser();

        $dependent = FamilyMember::factory()->dependent()->create(['owner_user_id' => $owner->getKey()]);
        $pendingLink = FamilyMember::factory()->linked()->create(['owner_user_id' => $owner->getKey()]);
        $approvedLink = FamilyMember::factory()->linked()->approved()->create(['owner_user_id' => $owner->getKey()]);
        $declinedLink = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'status' => FamilyMemberStatus::DECLINED,
        ]);

        $this->assertTrue($dependent->isDependent());
        $this->assertTrue($dependent->isUsableForBooking());
        $this->assertFalse($pendingLink->isDependent());
        $this->assertFalse($pendingLink->isUsableForBooking());
        $this->assertTrue($approvedLink->isUsableForBooking());
        $this->assertFalse($declinedLink->isUsableForBooking());
    }

    public function test_listed_clinic_helper_builds_a_discoverable_cabinet(): void
    {
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeListedClinic();

        $this->assertTrue($cabinet->isActive());
        $this->assertTrue($doctor->is_active);
        $this->assertDatabaseHas('doctor_profiles', [
            'id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('cabinet_public_profiles', [
            'cabinet_id' => $cabinet->getKey(),
            'is_listed' => true,
        ]);
    }
}
