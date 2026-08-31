<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Licensing\CabinetEntitlementIssuer;
use App\Licensing\CabinetEntitlementVerifier;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The control plane's half of offline activation. Until this existed the
 * verifier had nothing real to verify and `hub:activate` had no input that
 * could be produced outside a test fixture.
 */
class CabinetEntitlementIssuingTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKeyPath;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new RuntimeException('Could not generate a key pair.');

        openssl_pkey_export($key, $privatePem);

        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'drclick-priv').'.pem';
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'drclick-pub').'.pem';
        file_put_contents($this->privateKeyPath, $privatePem);
        file_put_contents($this->publicKeyPath, openssl_pkey_get_details($key)['key']);

        config([
            'medismart.licensing.public_key_path' => $this->publicKeyPath,
            'medismart.licensing.product' => 'medismart-desktop',
            'hub.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKeyPath);
        @unlink($this->publicKeyPath);
        parent::tearDown();
    }

    private function issuer(): CabinetEntitlementIssuer
    {
        return app(CabinetEntitlementIssuer::class);
    }

    private function verifier(): CabinetEntitlementVerifier
    {
        return app(CabinetEntitlementVerifier::class);
    }

    private function pendingCabinet(string $ownerEmail = 'owner@example.com'): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet du Dr Houdaifa',
            'status' => CabinetStatus::PENDING,
        ]);
        $owner = User::factory()->create([
            'email' => $ownerEmail,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $cabinet->refresh();
    }

    public function test_an_issued_entitlement_verifies_and_activates_a_cabinet_end_to_end(): void
    {
        $this->pendingCabinet();

        $envelope = $this->issuer()->issue(
            signingKeyPath: $this->privateKeyPath,
            ownerEmail: 'owner@example.com',
            plan: 'trial',
        );

        $entitlement = $this->verifier()->verify($envelope);
        $this->assertSame('trial', $entitlement->plan);
        $this->assertSame('owner@example.com', $entitlement->ownerEmail);
        $this->assertNotNull($entitlement->expiresAt);

        $cabinet = app(CabinetFulfillmentService::class)->activateFromOfflineEntitlement($entitlement);
        $this->assertTrue($cabinet->isActive());
    }

    public function test_a_lifetime_entitlement_carries_no_expiry(): void
    {
        $entitlement = $this->verifier()->verify(
            $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'lifetime'),
        );

        $this->assertTrue($entitlement->isLifetime());
        $this->assertNull($entitlement->expiresAt);
    }

    public function test_a_trial_honours_the_requested_duration(): void
    {
        $entitlement = $this->verifier()->verify(
            $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'trial', 30),
        );

        $this->assertTrue(
            $entitlement->expiresAt->equalTo($entitlement->issuedAt->addDays(30)),
            'the expiry must be exactly the requested number of days after issue',
        );
    }

    public function test_the_owner_address_is_canonicalised_the_way_the_application_stores_it(): void
    {
        $entitlement = $this->verifier()->verify(
            $this->issuer()->issue($this->privateKeyPath, '  Owner@Example.COM  ', 'lifetime'),
        );

        $this->assertSame('owner@example.com', $entitlement->ownerEmail);
    }

    public function test_a_hub_bound_entitlement_records_the_hub(): void
    {
        $entitlement = $this->verifier()->verify(
            $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'lifetime', null, 'hub-42'),
        );

        $this->assertSame('hub-42', $entitlement->hubId);
        $this->assertFalse($entitlement->boundToHub('hub-other'));
        $this->assertTrue($entitlement->boundToHub('hub-42'));
    }

    public function test_an_unbound_entitlement_omits_the_hub_claim_entirely(): void
    {
        $envelope = $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'lifetime');
        $payload = json_decode(
            base64_decode(strtr(json_decode($envelope, true)['payload'], '-_', '+/'), true),
            true,
        );

        $this->assertArrayNotHasKey('hub_id', $payload, 'an absent binding must not be sent as null');
    }

    public function test_a_cabinet_hub_may_never_issue_its_own_entitlement(): void
    {
        // The whole offline licence model rests on this. A Hub that could sign
        // for itself would make activation self-service.
        config(['hub.enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A Cabinet Hub must never issue its own entitlements.');

        $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'lifetime');
    }

    public function test_a_public_key_cannot_be_used_to_sign(): void
    {
        $this->expectExceptionMessage('The signing key could not be loaded.');

        $this->issuer()->issue($this->publicKeyPath, 'owner@example.com', 'lifetime');
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->expectExceptionMessage('Unknown plan "unlimited".');

        $this->issuer()->issue($this->privateKeyPath, 'owner@example.com', 'unlimited');
    }

    public function test_an_invalid_owner_address_is_refused(): void
    {
        $this->expectExceptionMessage('not a valid e-mail address');

        $this->issuer()->issue($this->privateKeyPath, 'not-an-address', 'lifetime');
    }

    public function test_an_undersized_signing_key_is_refused(): void
    {
        $weak = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($weak, $pem);
        $path = tempnam(sys_get_temp_dir(), 'drclick-weak').'.pem';
        file_put_contents($path, $pem);

        try {
            $this->expectExceptionMessage('not an approved RSA key');
            $this->issuer()->issue($path, 'owner@example.com', 'lifetime');
        } finally {
            @unlink($path);
        }
    }

    public function test_the_command_writes_a_file_that_hub_activate_accepts(): void
    {
        $this->pendingCabinet();
        $out = tempnam(sys_get_temp_dir(), 'entitlement').'.json';

        try {
            $this->artisan('license:issue-entitlement', [
                '--owner' => 'owner@example.com',
                '--plan' => 'lifetime',
                '--key' => $this->privateKeyPath,
                '--out' => $out,
            ])->assertExitCode(0);

            // The two commands are the real contract: what the control plane
            // signs must be what a Hub with no Internet can apply.
            $this->artisan('hub:activate', ['file' => $out])->assertExitCode(0);
            $this->assertTrue(Cabinet::query()->firstOrFail()->isActive());
        } finally {
            @unlink($out);
        }
    }

    public function test_the_command_refuses_a_key_that_does_not_match_the_deployed_public_key(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $path = tempnam(sys_get_temp_dir(), 'drclick-mismatch').'.pem';
        file_put_contents($path, $pem);

        try {
            $this->artisan('license:issue-entitlement', [
                '--owner' => 'owner@example.com',
                '--key' => $path,
            ])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_command_requires_an_owner_and_a_key(): void
    {
        $this->artisan('license:issue-entitlement')->assertExitCode(1);
    }
}
