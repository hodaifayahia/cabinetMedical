<?php

namespace Tests\Feature\Licensing;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Licensing\CabinetEntitlementVerifier;
use App\Models\Cabinet;
use App\Models\DesktopDownloadLead;
use App\Models\HostedLicenseGrant;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\Support\SignsCabinetEntitlements;
use Tests\TestCase;

/**
 * POST /api/v1/desktop/activate on the online service: an installed desktop
 * redeems its code (or its owner's online account) once and receives a signed
 * entitlement bound to that installation.
 */
class DesktopActivationApiTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase, SignsCabinetEntitlements;

    private const PASSWORD = 'mot-de-passe-en-ligne-2026';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpEntitlementKeys();
        config([
            'hub.enabled' => false,
            'medismart.licensing.entitlement_signing_key_path' => $this->entitlementPrivateKeyPath,
        ]);
    }

    /**
     * @return array{0: Cabinet, 1: string}
     */
    private function cabinetWithCode(LicensePlan $plan = LicensePlan::LIFETIME): array
    {
        [$cabinet] = $this->activeCabinetWithOwner('online-owner@example.com', CabinetStatus::PENDING);
        $platform = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)->issueLicenseCode($cabinet, $plan);
        auth()->forgetGuards();

        return [$cabinet, $issued->code];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function codePayload(string $code, array $overrides = []): array
    {
        return array_merge([
            'license_code' => $code,
            'installation_id' => (string) Str::uuid(),
            'owner_email' => 'desk-owner@example.com',
        ], $overrides);
    }

    public function test_a_valid_code_returns_an_entitlement_bound_to_the_installation(): void
    {
        [$cabinet, $code] = $this->cabinetWithCode();
        $payload = $this->codePayload($code);

        $response = $this->postJson('/api/v1/desktop/activate', $payload)
            ->assertOk()
            ->assertJsonPath('cabinet.name', $cabinet->name)
            ->assertJsonPath('license.plan', 'lifetime');

        $entitlement = app(CabinetEntitlementVerifier::class)->verify($response->json('entitlement'));

        $this->assertSame('desk-owner@example.com', $entitlement->ownerEmail);
        $this->assertSame($payload['installation_id'], $entitlement->hubId);
        $this->assertTrue($entitlement->isLifetime());

        // The online cabinet is activated too, so the owner can sign in to
        // the mobile API and link the poste afterwards.
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->refresh()->status);

        $grant = HostedLicenseGrant::withoutCabinetScope()->sole();
        $this->assertNotNull($grant->redeemed_at);
        $this->assertSame($payload['installation_id'], $grant->redeemed_installation_id);
        $this->assertSame('desk-owner@example.com', $grant->redeemed_owner_email);
    }

    public function test_a_trial_entitlement_carries_the_trial_expiry(): void
    {
        [, $code] = $this->cabinetWithCode(LicensePlan::TRIAL);

        $response = $this->postJson('/api/v1/desktop/activate', $this->codePayload($code))->assertOk();
        $entitlement = app(CabinetEntitlementVerifier::class)->verify($response->json('entitlement'));

        $this->assertSame('trial', $entitlement->plan);
        $this->assertNotNull($entitlement->expiresAt);
        $this->assertTrue($entitlement->expiresAt->isFuture());
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->cabinetWithCode();

        $this->postJson('/api/v1/desktop/activate', $this->codePayload('DRDZ-0000-0000-0000-0000'))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_code');
    }

    public function test_a_code_already_used_on_another_poste_is_refused(): void
    {
        [, $code] = $this->cabinetWithCode();

        $this->postJson('/api/v1/desktop/activate', $this->codePayload($code))->assertOk();

        $this->postJson('/api/v1/desktop/activate', $this->codePayload($code))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'code_already_used');
    }

    public function test_the_same_poste_may_retry_after_a_lost_answer(): void
    {
        [, $code] = $this->cabinetWithCode();
        $payload = $this->codePayload($code);

        $this->postJson('/api/v1/desktop/activate', $payload)->assertOk();
        $retry = $this->postJson('/api/v1/desktop/activate', $payload)->assertOk();

        $entitlement = app(CabinetEntitlementVerifier::class)->verify($retry->json('entitlement'));
        $this->assertSame($payload['installation_id'], $entitlement->hubId);
        $this->assertSame(1, HostedLicenseGrant::withoutCabinetScope()->whereNotNull('redeemed_at')->count());
    }

    public function test_without_a_signing_key_the_code_is_not_spent(): void
    {
        config(['medismart.licensing.entitlement_signing_key_path' => null]);
        [, $code] = $this->cabinetWithCode();

        $this->postJson('/api/v1/desktop/activate', $this->codePayload($code))
            ->assertStatus(503)
            ->assertJsonPath('reason', 'activation_unavailable');

        $this->assertTrue(HostedLicenseGrant::withoutCabinetScope()->sole()->isOutstanding());
    }

    public function test_a_hub_never_signs_entitlements(): void
    {
        config(['hub.enabled' => true]);
        [, $code] = $this->cabinetWithCode();

        $this->postJson('/api/v1/desktop/activate', $this->codePayload($code))
            ->assertStatus(503);
    }

    public function test_the_owner_of_an_active_online_cabinet_gets_an_entitlement_and_a_link_token(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('active-owner@example.com', CabinetStatus::PENDING);
        $owner->forceFill(['password' => self::PASSWORD])->save();
        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::LIFETIME);
        $installationId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/desktop/activate', [
            'email' => 'Active-Owner@example.com',
            'password' => self::PASSWORD,
            'installation_id' => $installationId,
            'link' => true,
        ])->assertOk()
            ->assertJsonPath('account.email', 'active-owner@example.com')
            ->assertJsonPath('cabinet.owner_email', 'active-owner@example.com');

        $this->assertIsString($response->json('token'));
        $entitlement = app(CabinetEntitlementVerifier::class)->verify($response->json('entitlement'));
        $this->assertSame('active-owner@example.com', $entitlement->ownerEmail);
        $this->assertSame($installationId, $entitlement->hubId);
        $this->assertTrue($entitlement->isLifetime());
    }

    public function test_a_wrong_password_is_refused(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('active-owner@example.com');
        $owner->forceFill(['password' => self::PASSWORD])->save();

        $this->postJson('/api/v1/desktop/activate', [
            'email' => 'active-owner@example.com',
            'password' => 'wrong',
            'installation_id' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('reason', 'invalid_credentials');
    }

    public function test_a_pending_online_cabinet_cannot_activate_a_poste_by_account(): void
    {
        [, $owner] = $this->activeCabinetWithOwner('pending-owner@example.com', CabinetStatus::PENDING);
        $owner->forceFill(['password' => self::PASSWORD])->save();

        $this->postJson('/api/v1/desktop/activate', [
            'email' => 'pending-owner@example.com',
            'password' => self::PASSWORD,
            'installation_id' => (string) Str::uuid(),
        ])->assertStatus(403)->assertJsonPath('reason', 'access_denied');
    }

    public function test_only_the_owner_may_activate_a_poste_by_account(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('team-owner@example.com');
        $member = User::factory()->create([
            'email' => 'assistant@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
            'password' => self::PASSWORD,
        ]);
        $member->assignRole(RoleName::ASSISTANT->value);

        $this->postJson('/api/v1/desktop/activate', [
            'email' => 'assistant@example.com',
            'password' => self::PASSWORD,
            'installation_id' => (string) Str::uuid(),
        ])->assertStatus(403)->assertJsonPath('reason', 'not_owner');
    }

    public function test_a_doctor_who_activated_the_desktop_first_can_then_create_the_online_account_and_link(): void
    {
        // The Windows download form opened an ownerless pending cabinet, and
        // the administrator issued its code to the doctor's e-mail.
        $cabinet = Cabinet::query()->create(['name' => 'Cabinet Téléchargement', 'status' => CabinetStatus::PENDING]);
        DesktopDownloadLead::query()->create([
            'name' => 'Dr Karim',
            'email' => 'karim@example.com',
            'phone' => '0550112233',
            'cabinet_name' => 'Cabinet Téléchargement',
            'specialization' => 'Pédiatrie',
            'cabinet_id' => $cabinet->getKey(),
            'downloaded_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
        $code = app(CabinetFulfillmentService::class)->issueLicenseCode($cabinet, LicensePlan::LIFETIME)->code;
        auth()->forgetGuards();

        // The desktop is activated first…
        $this->postJson('/api/v1/desktop/activate', $this->codePayload($code, ['owner_email' => 'karim@example.com']))
            ->assertOk();
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->refresh()->status);

        // …then the doctor creates the online account with the same e-mail:
        // it claims that active cabinet instead of opening a pending one.
        $this->post(route('register.store'), [
            'name' => 'Dr Karim',
            'cabinet_name' => 'Cabinet Karim',
            'specialization' => 'Pédiatrie',
            'phone' => '+213 555 11 22 33',
            'email' => 'karim@example.com',
            'wilaya' => 16,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();
        auth()->forgetGuards();

        $this->assertSame(1, Cabinet::query()->count());
        $cabinet->refresh();
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->status);
        $this->assertNotNull($cabinet->license_id);
        $this->assertSame('karim@example.com', $cabinet->owner?->email);

        // So the desktop can now be linked (the token endpoint the Service en
        // ligne page and the mobile app use).
        $this->postJson('/api/v1/auth/token', [
            'email' => 'karim@example.com',
            'password' => self::PASSWORD,
            'device_name' => 'Poste Drclick',
        ])->assertOk()->assertJsonStructure(['token']);
    }
}
