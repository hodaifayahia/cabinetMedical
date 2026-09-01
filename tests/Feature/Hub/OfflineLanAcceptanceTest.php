<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Licensing\CabinetEntitlementVerifier;
use App\Models\Cabinet;
use App\Models\Patient;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use App\Services\Hub\HubAdoptionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * The automated half of ADR-002's acceptance criteria for the shared-offline
 * claim: two cabinet users working against one Cabinet Hub with the Internet
 * unavailable, on one authoritative database, with no cross-cabinet access.
 *
 * Every test here runs under Http::preventStrayRequests(), so any outbound
 * HTTP call the application attempts fails the test rather than silently
 * succeeding on a developer machine that happens to be online.
 *
 * What this does NOT cover, and what still needs a manual run on real
 * hardware, is listed in the ADR: two physical Windows desktops, restart and
 * power-loss recovery, backup restore to replacement hardware, and proof that
 * no plaintext medical traffic crosses the LAN.
 */
class OfflineLanAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // The Hub has no Internet for the whole of every test below.
        Http::preventStrayRequests();

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new RuntimeException('Could not generate a signing key.');

        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'drclick-lan').'.pem';
        file_put_contents($this->publicKeyPath, openssl_pkey_get_details($this->privateKey)['key']);

        config([
            'medismart.licensing.public_key_path' => $this->publicKeyPath,
            'medismart.licensing.product' => 'medismart-desktop',
            // Inertia server-side rendering calls a local Node process. That is
            // a rendering detail rather than an Internet dependency, but it is
            // still an outbound HTTP call, so it is turned off here to keep
            // preventStrayRequests() meaningful. A real Hub must either run
            // that process locally or disable SSR — it must never be pointed at
            // a remote renderer.
            'inertia.ssr.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicKeyPath);
        parent::tearDown();
    }

    private function signedEntitlement(string $ownerEmail): string
    {
        $payload = [
            'entitlement_version' => 1,
            'entitlement_id' => 'ENT-'.bin2hex(random_bytes(6)),
            'product' => 'medismart-desktop',
            'owner_email' => $ownerEmail,
            'plan' => 'lifetime',
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => null,
        ];

        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        openssl_sign($encoded, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $encoded,
            'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A cabinet activated entirely offline, with a doctor and a reception
     * account — the two roles that share a cabinet LAN.
     *
     * @return array{Cabinet, User, User}
     */
    private function activatedCabinetWithBothRoles(): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet du Dr Houdaifa',
            'status' => CabinetStatus::PENDING,
        ]);

        $doctor = User::factory()->create([
            'email' => 'doctor@cabinet.dz',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $doctor->assignRole(RoleName::DOCTOR->value);
        $cabinet->forceFill(['owner_user_id' => $doctor->getKey()])->save();

        $reception = User::factory()->create([
            'email' => 'reception@cabinet.dz',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $reception->assignRole(RoleName::ASSISTANT->value);

        app(CabinetFulfillmentService::class)->activateFromOfflineEntitlement(
            app(CabinetEntitlementVerifier::class)->verify($this->signedEntitlement('doctor@cabinet.dz')),
        );

        return [$cabinet->refresh(), $doctor->refresh(), $reception->refresh()];
    }

    private function runAsHubFor(Cabinet $cabinet): void
    {
        config([
            'hub.enabled' => true,
            'hub.id' => 'hub-lan-acceptance',
            'hub.cabinet_id' => $cabinet->getKey(),
        ]);

        app(HubAdoptionService::class)->adopt();
    }

    public function test_a_cabinet_goes_from_pending_to_working_without_ever_reaching_the_internet(): void
    {
        [$cabinet, $doctor, $reception] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        $this->assertTrue($cabinet->isActive());

        foreach ([$doctor, $reception] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk();
        }
    }

    public function test_both_desktops_sign_in_with_their_own_credentials(): void
    {
        [$cabinet, $doctor, $reception] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        // Desktop 1: the doctor.
        $this->post('/login', ['email' => $doctor->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($doctor);
        $this->post('/logout');

        // Desktop 2: the reception desk, its own account on the same Hub.
        $this->post('/login', ['email' => $reception->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($reception);
    }

    public function test_reception_registers_a_patient_that_the_doctor_immediately_sees(): void
    {
        [$cabinet, $doctor, $reception] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        // One authoritative database: the reception desk writes...
        $this->actingAs($reception)
            ->post(route('app.patients.store'), [
                'first_name' => 'Amina',
                'last_name' => 'Benali',
                'phone' => '+213 555 00 11 22',
            ])
            ->assertSessionHasNoErrors();

        $patient = Patient::query()->where('last_name', 'Benali')->first();
        $this->assertNotNull($patient, 'reception could not register a patient offline');
        $this->assertSame($cabinet->getKey(), $patient->cabinet_id);

        // ...and the doctor reads it back on the other desktop.
        $this->actingAs($doctor)
            ->get(route('app.patients.show', $patient))
            ->assertOk();
    }

    public function test_both_roles_write_concurrently_to_the_one_authoritative_database(): void
    {
        [$cabinet, $doctor, $reception] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        $this->actingAs($reception)->post(route('app.patients.store'), [
            'first_name' => 'Amina', 'last_name' => 'Benali',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->post(route('app.patients.store'), [
            'first_name' => 'Karim', 'last_name' => 'Haddad',
        ])->assertSessionHasNoErrors();

        // Two writers, one cabinet, no divergence and no second database.
        $this->assertSame(2, Patient::query()->where('cabinet_id', $cabinet->getKey())->count());
        $this->assertSame(2, Patient::withoutCabinetScope()->count());
    }

    public function test_another_cabinets_records_are_unreachable_from_this_hub(): void
    {
        [$cabinet, $doctor] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        $neighbour = Cabinet::query()->create([
            'name' => 'Cabinet voisin',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $neighbourPatient = Patient::withoutCabinetScope()->create([
            'cabinet_id' => $neighbour->getKey(),
            'first_name' => 'Secret',
            'last_name' => 'Patient',
        ]);

        $this->actingAs($doctor)
            ->get(route('app.patients.show', $neighbourPatient))
            ->assertNotFound();
    }

    public function test_a_neighbouring_cabinets_user_cannot_use_this_hub_at_all(): void
    {
        [$cabinet] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        $neighbour = Cabinet::query()->create([
            'name' => 'Cabinet voisin',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $stranger = User::factory()->create([
            'cabinet_id' => $neighbour->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($stranger)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_hub_reports_itself_ready_to_a_desktop_that_has_no_internet(): void
    {
        [$cabinet] = $this->activatedCabinetWithBothRoles();
        $this->runAsHubFor($cabinet);

        $this->getJson('/health')
            ->assertJsonPath('hub.mode', 'hub')
            ->assertJsonPath('hub.ready', true)
            ->assertJsonPath('hub.cabinet_id', $cabinet->getKey());
    }
}
