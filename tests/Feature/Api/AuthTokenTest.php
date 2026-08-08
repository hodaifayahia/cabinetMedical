<?php

namespace Tests\Feature\Api;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Models\License;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_active_cabinet_owner_receives_a_plain_text_token(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('owner@example.com');

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'owner@example.com',
            'password' => 'password',
            'device_name' => 'iPhone de test',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions', 'cabinet']]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_pending_cabinet_is_rejected_with_403(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('pending@example.com', CabinetStatus::PENDING);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'pending@example.com',
            'password' => 'password',
            'device_name' => 'device',
        ])->assertStatus(403)
            ->assertJsonPath('reason', 'cabinet_pending');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_suspended_cabinet_is_rejected_with_403(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('sus@example.com', CabinetStatus::SUSPENDED);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'sus@example.com',
            'password' => 'password',
            'device_name' => 'device',
        ])->assertStatus(403)
            ->assertJsonPath('reason', 'cabinet_suspended');
    }

    public function test_expired_trial_is_rejected_with_a_clear_french_status(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('expired@example.com');
        $license = License::query()->create([
            'license_id' => 'CAB-EXPIRED-001',
            'product' => 'Drclick',
            'edition' => 'hosted',
            'plan' => LicensePlan::TRIAL,
            'customer_id' => (string) $cabinet->getKey(),
            'status' => 'active',
            'issued_at' => now()->subDays(8),
            'expires_at' => now()->subDay(),
            'last_verified_at' => now()->subDays(8),
        ]);
        $cabinet->forceFill(['license_id' => $license->getKey()])->save();

        $this->postJson('/api/v1/auth/token', [
            'email' => 'expired@example.com',
            'password' => 'password',
            'device_name' => 'device',
        ])->assertStatus(403)
            ->assertJsonPath('reason', 'license_expired')
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('message', "Votre essai de 7 jours est expiré. Contactez l'administration Drclick pour renouveler votre licence ou passer à une licence à vie.");

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_response_exposes_the_hosted_license_plan_and_expiry(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('licensed@example.com');
        $expiresAt = now()->addDays(7)->startOfSecond();
        $license = License::query()->create([
            'license_id' => 'CAB-ACTIVE-001',
            'product' => 'Drclick',
            'edition' => 'hosted',
            'plan' => LicensePlan::TRIAL,
            'customer_id' => (string) $cabinet->getKey(),
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            'last_verified_at' => now(),
        ]);
        $cabinet->forceFill(['license_id' => $license->getKey()])->save();

        $this->postJson('/api/v1/auth/token', [
            'email' => 'licensed@example.com',
            'password' => 'password',
            'device_name' => 'device',
        ])->assertOk()
            ->assertJsonPath('user.cabinet.license.plan', 'trial')
            ->assertJsonPath('user.cabinet.license.plan_label', 'Essai de 7 jours')
            ->assertJsonPath('user.cabinet.license.status', 'active')
            ->assertJsonPath('user.cabinet.license.expires_at', $expiresAt->toIso8601String());
    }

    public function test_unapproved_member_is_rejected_with_403(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('owner2@example.com');

        $member = User::factory()->create([
            'email' => 'member@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'member@example.com',
            'password' => 'password',
            'device_name' => 'device',
        ])->assertStatus(403)
            ->assertJsonPath('reason', 'awaiting_approval');
    }

    public function test_wrong_password_is_rejected_with_422(): void
    {
        $this->activeCabinetWithOwner('owner3@example.com');

        $this->postJson('/api/v1/auth/token', [
            'email' => 'owner3@example.com',
            'password' => 'wrong-password',
            'device_name' => 'device',
        ])->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_me_returns_the_user_with_cabinet_roles_and_permissions(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('owner4@example.com');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner4@example.com')
            ->assertJsonPath('data.cabinet.status', 'active')
            ->assertJsonStructure(['data' => ['roles', 'permissions', 'cabinet' => ['id', 'name', 'wilaya']]]);
    }

    public function test_me_never_leaks_the_password_hash(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('owner5@example.com');
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/me')->assertOk();
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json('data'))));
    }

    public function test_logout_revokes_the_current_token(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('owner6@example.com');

        $token = $owner->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The revoked token can no longer authenticate.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }
}
