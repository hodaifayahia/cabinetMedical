<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Licensing\CabinetEntitlementVerifier;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-002 invariant 8: a Hub may be given a signed, cabinet-bound entitlement
 * and trust it offline.
 *
 * These sign real envelopes with a real RSA key, so the signature check is
 * genuinely exercised rather than stubbed.
 */
class OfflineCabinetActivationTest extends TestCase
{
    use RefreshDatabase;

    private OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new RuntimeException('Could not generate a signing key.');

        $details = openssl_pkey_get_details($this->privateKey);
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'drclick-pub').'.pem';
        file_put_contents($this->publicKeyPath, $details['key']);

        config([
            'medismart.licensing.public_key_path' => $this->publicKeyPath,
            'medismart.licensing.product' => 'medismart-desktop',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicKeyPath);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entitlement(array $overrides = []): string
    {
        $payload = array_merge([
            'entitlement_version' => 1,
            'entitlement_id' => 'ENT-'.bin2hex(random_bytes(6)),
            'product' => 'medismart-desktop',
            'owner_email' => 'owner@example.com',
            'plan' => 'trial',
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addDays(7)->toIso8601String(),
        ], $overrides);

        $encoded = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        openssl_sign($encoded, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $encoded,
            'signature' => $this->base64Url($signature),
        ], JSON_THROW_ON_ERROR);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * A trial entitlement upgraded to lifetime after signing. The payload is
     * base64url-encoded inside the envelope, so it has to be decoded, edited
     * and re-encoded — the signature is deliberately left as it was.
     */
    private function tamperedEntitlement(): string
    {
        $envelope = json_decode($this->entitlement(), true, flags: JSON_THROW_ON_ERROR);
        $forged = json_decode(
            base64_decode(strtr($envelope['payload'], '-_', '+/'), true),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $forged['plan'] = 'lifetime';
        $forged['expires_at'] = null;
        $envelope['payload'] = $this->base64Url(json_encode($forged, JSON_THROW_ON_ERROR));

        return json_encode($envelope, JSON_THROW_ON_ERROR);
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

    private function fulfillment(): CabinetFulfillmentService
    {
        return app(CabinetFulfillmentService::class);
    }

    private function verifier(): CabinetEntitlementVerifier
    {
        return app(CabinetEntitlementVerifier::class);
    }

    public function test_a_pending_cabinet_activates_offline_from_a_signed_entitlement(): void
    {
        // No network at all: any outbound call fails the test.
        Http::preventStrayRequests();

        $cabinet = $this->pendingCabinet();
        $this->assertTrue($cabinet->isPending());

        $entitlement = $this->verifier()->verify($this->entitlement());
        $activated = $this->fulfillment()->activateFromOfflineEntitlement($entitlement);

        $this->assertTrue($activated->isActive());
        $this->assertNotNull($activated->license_id);
        $this->assertSame('trial', $activated->license->plan->value);
        $this->assertNotNull($activated->license->expires_at);
    }

    public function test_a_lifetime_entitlement_never_expires(): void
    {
        $this->pendingCabinet();

        $entitlement = $this->verifier()->verify(
            $this->entitlement(['plan' => 'lifetime', 'expires_at' => null]),
        );
        $activated = $this->fulfillment()->activateFromOfflineEntitlement($entitlement);

        $this->assertTrue($activated->isActive());
        $this->assertNull($activated->license->expires_at);
        $this->assertTrue($entitlement->isLifetime());
    }

    public function test_the_activated_cabinet_lets_its_members_into_the_application(): void
    {
        $cabinet = $this->pendingCabinet();
        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        // Before: held on the activation screen.
        $this->actingAs($member)->get('/dashboard')->assertRedirect(route('cabinet.pending'));

        $this->fulfillment()->activateFromOfflineEntitlement(
            $this->verifier()->verify($this->entitlement()),
        );

        $this->actingAs($member->refresh())->get('/dashboard')->assertOk();
    }

    public function test_a_tampered_entitlement_is_refused(): void
    {
        $this->pendingCabinet();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The entitlement signature is invalid.');

        $this->verifier()->verify($this->tamperedEntitlement());
    }

    public function test_an_entitlement_signed_by_the_wrong_key_is_refused(): void
    {
        $this->pendingCabinet();
        $envelope = $this->entitlement();

        // Rotate the trusted key to a different one after signing.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $path = tempnam(sys_get_temp_dir(), 'drclick-other').'.pem';
        file_put_contents($path, openssl_pkey_get_details($other)['key']);
        config(['medismart.licensing.public_key_path' => $path]);

        try {
            $this->expectException(RuntimeException::class);
            $this->verifier()->verify($envelope);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_entitlement_for_another_product_is_refused(): void
    {
        $this->pendingCabinet();

        $this->expectExceptionMessage('The entitlement was issued for another product.');

        $this->verifier()->verify($this->entitlement(['product' => 'someone-elses-app']));
    }

    public function test_an_entitlement_for_an_unknown_owner_activates_nothing(): void
    {
        $this->pendingCabinet('owner@example.com');

        $entitlement = $this->verifier()->verify(
            $this->entitlement(['owner_email' => 'stranger@example.com']),
        );

        $this->expectExceptionMessage('Aucun cabinet ne correspond à cette clé d’activation.');
        $this->fulfillment()->activateFromOfflineEntitlement($entitlement);
    }

    public function test_the_same_entitlement_cannot_be_applied_twice(): void
    {
        $this->pendingCabinet();
        $envelope = $this->entitlement();

        $this->fulfillment()->activateFromOfflineEntitlement($this->verifier()->verify($envelope));

        $this->expectExceptionMessage('Cette clé d’activation a déjà été utilisée.');
        $this->fulfillment()->activateFromOfflineEntitlement($this->verifier()->verify($envelope));
    }

    public function test_an_expired_entitlement_is_refused(): void
    {
        $this->pendingCabinet();

        $entitlement = $this->verifier()->verify($this->entitlement([
            'issued_at' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
        ]));

        $this->expectExceptionMessage('Cette clé d’activation est expirée.');
        $this->fulfillment()->activateFromOfflineEntitlement($entitlement);
    }

    public function test_an_entitlement_bound_to_another_hub_is_refused(): void
    {
        $this->pendingCabinet();

        $entitlement = $this->verifier()->verify($this->entitlement(['hub_id' => 'hub-somewhere-else']));

        $this->expectExceptionMessage('Cette clé d’activation a été émise pour un autre Hub.');
        $this->fulfillment()->activateFromOfflineEntitlement($entitlement, 'hub-this-one');
    }

    public function test_an_entitlement_bound_to_this_hub_is_accepted(): void
    {
        $this->pendingCabinet();

        $entitlement = $this->verifier()->verify($this->entitlement(['hub_id' => 'hub-this-one']));
        $activated = $this->fulfillment()->activateFromOfflineEntitlement($entitlement, 'hub-this-one');

        $this->assertTrue($activated->isActive());
    }

    public function test_an_entitlement_from_a_newer_release_is_refused_rather_than_guessed_at(): void
    {
        $this->pendingCabinet();

        $this->expectExceptionMessage('This entitlement was issued for a newer version of Drclick.');
        $this->verifier()->verify($this->entitlement(['entitlement_version' => 2]));
    }

    public function test_an_unknown_plan_is_refused_rather_than_defaulted(): void
    {
        $this->pendingCabinet();

        $entitlement = $this->verifier()->verify($this->entitlement(['plan' => 'unlimited-everything']));

        $this->expectExceptionMessage('Le type de licence de cette clé est inconnu.');
        $this->fulfillment()->activateFromOfflineEntitlement($entitlement);
    }

    public function test_the_hub_activate_command_applies_an_entitlement_file(): void
    {
        $this->pendingCabinet();
        $path = tempnam(sys_get_temp_dir(), 'entitlement').'.json';
        file_put_contents($path, $this->entitlement());

        try {
            $this->artisan('hub:activate', ['file' => $path])
                ->expectsOutputToContain('Cabinet du Dr Houdaifa')
                ->assertExitCode(0);

            $this->assertTrue(Cabinet::query()->firstOrFail()->isActive());
        } finally {
            @unlink($path);
        }
    }

    public function test_the_hub_activate_command_refuses_a_tampered_file(): void
    {
        $this->pendingCabinet();
        $path = tempnam(sys_get_temp_dir(), 'entitlement').'.json';
        file_put_contents($path, $this->tamperedEntitlement());

        try {
            $this->artisan('hub:activate', ['file' => $path])->assertExitCode(1);
            $this->assertTrue(Cabinet::query()->firstOrFail()->isPending());
        } finally {
            @unlink($path);
        }
    }
}
