<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Role boundary of the platform back office. Every clinic-side token —
 * patient, doctor, reception — is refused on every admin route with the same
 * machine-readable reason, and an anonymous caller never gets past
 * authentication. Only is_platform_admin opens the door.
 */
class AdminBoundaryTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_patient_token_is_refused_on_every_admin_route(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($this->makePatientUser(), ['mobile']);

        $this->assertEveryAdminRouteIsForbidden((int) $clinic['cabinet']->getKey());
    }

    public function test_a_doctor_token_is_refused_on_every_admin_route(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser'], ['mobile']);

        $this->assertEveryAdminRouteIsForbidden((int) $clinic['cabinet']->getKey());
    }

    public function test_a_reception_token_is_refused_on_every_admin_route(): void
    {
        $clinic = $this->makeListedClinic();

        $reception = User::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'approved_at' => now(),
        ]);
        $reception->assignRole(RoleName::ASSISTANT->value);

        Sanctum::actingAs($reception, ['mobile']);

        $this->assertEveryAdminRouteIsForbidden((int) $clinic['cabinet']->getKey());
    }

    public function test_an_unauthenticated_caller_gets_401_on_every_admin_route(): void
    {
        $clinic = $this->makeListedClinic();

        foreach ($this->adminRoutes((int) $clinic['cabinet']->getKey()) as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(401);
        }
    }

    public function test_a_platform_admin_token_is_admitted(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->getJson('/api/v1/admin/cabinets')->assertOk();
        $this->getJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey())->assertOk();
    }

    /**
     * A refusal must be a 403 carrying the reason code — never a 404 that
     * hides whether the resource exists, and never a partial 200.
     */
    private function assertEveryAdminRouteIsForbidden(int $cabinetId): void
    {
        foreach ($this->adminRoutes($cabinetId) as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(403)
                ->assertJsonPath('reason', 'platform_admin_required')
                ->assertJsonMissingPath('data');
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function adminRoutes(int $cabinetId): array
    {
        return [
            ['get', '/api/v1/admin/overview'],
            ['get', '/api/v1/admin/cabinets'],
            ['post', '/api/v1/admin/cabinets'],
            ['get', "/api/v1/admin/cabinets/{$cabinetId}"],
            ['post', "/api/v1/admin/cabinets/{$cabinetId}/activate"],
            ['post', "/api/v1/admin/cabinets/{$cabinetId}/suspend"],
            ['post', "/api/v1/admin/cabinets/{$cabinetId}/staff"],
            ['patch', "/api/v1/admin/cabinets/{$cabinetId}/listing"],
        ];
    }
}
