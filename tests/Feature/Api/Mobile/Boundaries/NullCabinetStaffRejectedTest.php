<?php

namespace Tests\Feature\Api\Mobile\Boundaries;

use App\Enums\RoleName;
use App\Models\CabinetPublicProfile;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * The staff-mobile group relies on the BelongsToCabinet global scope, which
 * is bypassed for platform admins and inert for legacy null-cabinet staff:
 * for both, an implicitly-scoped query would resolve against an arbitrary
 * tenant. Regression suite for the mobile.staff.cabinet gate that rejects
 * them — 403 with a machine-readable reason, and never a wrong-tenant read
 * or write.
 */
class NullCabinetStaffRejectedTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_is_rejected_on_every_staff_mobile_endpoint(): void
    {
        $this->makeListedClinic();

        $admin = User::factory()->create([
            'cabinet_id' => null,
            'is_platform_admin' => true,
            'approved_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $endpoints = [
            ['getJson', '/api/v1/mobile/appointments/today', []],
            ['getJson', '/api/v1/mobile/clinic-profile', []],
            ['putJson', '/api/v1/mobile/clinic-profile', ['is_listed' => false]],
            ['postJson', '/api/v1/mobile/patients', ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0550000000']],
            ['putJson', '/api/v1/mobile/schedule', ['days' => []]],
            ['postJson', '/api/v1/mobile/schedule/time-off', ['starts_at' => '2027-01-04', 'ends_at' => '2027-01-04']],
        ];

        foreach ($endpoints as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath('reason', 'cabinet_membership_required');
        }
    }

    public function test_platform_admin_cannot_write_an_arbitrary_cabinets_public_profile(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();

        $admin = User::factory()->create([
            'cabinet_id' => null,
            'is_platform_admin' => true,
            'approved_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'is_listed' => false,
            'about' => 'hijacked',
        ])->assertStatus(403);

        // The first cabinet's listing was not touched.
        $profile = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->firstOrFail();
        $this->assertTrue($profile->is_listed);
        $this->assertNotSame('hijacked', $profile->about);
    }

    public function test_legacy_null_cabinet_staff_is_rejected(): void
    {
        $this->makeListedClinic();

        $legacy = User::factory()->create([
            'cabinet_id' => null,
            'approved_at' => now(),
        ]);
        $legacy->assignRole(RoleName::DOCTOR->value);
        Sanctum::actingAs($legacy);

        $this->getJson('/api/v1/mobile/appointments/today')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'cabinet_membership_required');

        $this->postJson('/api/v1/mobile/patients', [
            'first_name' => 'Orphan',
            'last_name' => 'Row',
            'phone' => '0551111111',
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'cabinet_membership_required');

        // No orphan (cabinet_id null) dossier was created.
        $this->assertSame(0, Patient::withoutCabinetScope()
            ->where('phone', '0551111111')
            ->count());
    }

    public function test_regular_cabinet_staff_still_passes_the_gate(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->getJson('/api/v1/mobile/clinic-profile')->assertOk();
        $this->getJson('/api/v1/mobile/appointments/today')->assertOk();
    }
}
