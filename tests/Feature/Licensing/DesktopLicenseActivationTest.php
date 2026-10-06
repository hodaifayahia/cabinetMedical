<?php

namespace Tests\Feature\Licensing;

use App\Enums\CabinetStatus;
use App\Licensing\CloudActivationClient;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\MachineFingerprintService;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\Support\SignsCabinetEntitlements;
use Tests\TestCase;

/**
 * An installed desktop is activated once — with a code checked on the online
 * service, with the cabinet's online account, or offline with a licence file
 * — and then works with no Internet at all.
 */
class DesktopLicenseActivationTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase, SignsCabinetEntitlements;

    private const CLOUD = 'https://cloud.drclick.test';

    private const OWNER = 'desk-owner@example.com';

    private Cabinet $cabinet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpEntitlementKeys();
        config([
            'medismart.online_service.linkable' => true,
            'medismart.online_service.url' => self::CLOUD,
            // A desktop never holds the signing key.
            'medismart.licensing.entitlement_signing_key_path' => null,
        ]);

        [$this->cabinet, $this->owner] = $this->activeCabinetWithOwner(self::OWNER, CabinetStatus::PENDING);
    }

    private function installationId(): string
    {
        return app(MachineFingerprintService::class)->installationId();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeCloud(array $body, int $status = 200, array $seats = []): void
    {
        Http::fake([
            self::CLOUD.'/api/v1/desktop/activate' => Http::response($body, $status),
            self::CLOUD.'/api/v1/cabinet/seats' => Http::response(['data' => array_merge([
                'seat_limit' => 5,
                'seats_in_use' => 1,
                'owner_email' => self::OWNER,
            ], $seats)]),
            '*' => Http::response([], 404),
        ]);
    }

    private function validEntitlement(array $overrides = []): string
    {
        return $this->signedEntitlement(array_merge([
            'owner_email' => self::OWNER,
            'hub_id' => $this->installationId(),
        ], $overrides));
    }

    public function test_the_activation_screen_offers_every_desktop_path(): void
    {
        $this->actingAs($this->owner)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/PendingActivation')
                ->where('can_redeem_license', true)
                ->where('desktop_activation.online', true)
                ->where('desktop_activation.license_file', true)
                ->where('desktop_activation.owner_email', self::OWNER));
    }

    public function test_a_code_unknown_locally_is_redeemed_on_the_online_service_once(): void
    {
        $this->fakeCloud(['entitlement' => $this->validEntitlement(), 'cabinet' => ['name' => 'Cabinet en ligne']]);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA-BBBB-CCCC-DDDD'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->cabinet->refresh();
        $this->assertSame(CabinetStatus::ACTIVE, $this->cabinet->status);
        $this->assertNull($this->cabinet->license?->expires_at, 'A lifetime licence never expires.');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::CLOUD.'/api/v1/desktop/activate'
            && $request['license_code'] === 'DRDZ-AAAA-BBBB-CCCC-DDDD'
            && $request['owner_email'] === self::OWNER
            && $request['installation_id'] === $this->installationId());

        // From now on the cabinet opens with no Internet at all.
        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->actingAs($this->owner->refresh())
            ->get(route('cabinet.pending'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_entitlement_with_a_bad_signature_is_refused(): void
    {
        $forged = $this->signedEntitlement([
            'owner_email' => self::OWNER,
            'hub_id' => $this->installationId(),
        ], $this->generateRsaKey());
        $this->fakeCloud(['entitlement' => $forged]);

        $this->actingAs($this->owner)
            ->from(route('cabinet.pending'))
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertRedirect(route('cabinet.pending'))
            ->assertSessionHasErrors(['license_code' => 'La licence reçue du service en ligne n’a pas pu être vérifiée (signature refusée). '
                .'Ce poste n’a pas été activé. Contactez le support Drclick.']);

        $this->assertSame(CabinetStatus::PENDING, $this->cabinet->refresh()->status);
    }

    public function test_an_entitlement_for_another_poste_is_refused(): void
    {
        $this->fakeCloud(['entitlement' => $this->validEntitlement(['hub_id' => '0d6f6c43-5b0a-4bd2-9c39-1f0f0f0f0f0f'])]);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors('license_code');

        $this->assertSame(CabinetStatus::PENDING, $this->cabinet->refresh()->status);
    }

    public function test_no_internet_is_explained_in_french(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors(['license_code' => CloudActivationClient::OFFLINE_MESSAGE])
            ->assertSessionHas('activation_offline', true);

        $this->assertSame(CabinetStatus::PENDING, $this->cabinet->refresh()->status);
    }

    public function test_a_code_used_on_another_poste_is_explained(): void
    {
        $this->fakeCloud(['message' => 'refusé', 'reason' => 'code_already_used'], 409);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors('license_code');

        $this->assertStringContainsString(
            'déjà été utilisé sur un autre poste',
            session('errors')->first('license_code'),
        );
    }

    public function test_an_invalid_code_is_explained(): void
    {
        $this->fakeCloud(['message' => 'x', 'reason' => 'invalid_code'], 422);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors(['license_code' => 'Ce code de licence est invalide ou n’est plus disponible. Vérifiez sa saisie.']);
    }

    public function test_without_the_verification_key_no_code_is_sent(): void
    {
        config(['medismart.licensing.public_key_path' => '/missing/key.pem']);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors('license_code');

        Http::assertNothingSent();
    }

    public function test_the_online_service_itself_never_calls_itself(): void
    {
        config(['medismart.online_service.linkable' => false]);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => 'DRDZ-AAAA'])
            ->assertSessionHasErrors(['license_code' => 'Ce code de licence est invalide ou n’est plus disponible.']);

        Http::assertNothingSent();
    }

    public function test_a_signed_licence_file_activates_with_no_network(): void
    {
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->createWithContent('licence-drclick.json', $this->signedEntitlement([
            'owner_email' => self::OWNER,
        ]));

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.file'), ['entitlement_file' => $file])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame(CabinetStatus::ACTIVE, $this->cabinet->refresh()->status);
    }

    public function test_a_tampered_licence_file_is_refused(): void
    {
        $envelope = json_decode($this->signedEntitlement(['owner_email' => self::OWNER, 'plan' => 'trial', 'expires_at' => now()->addDay()->toIso8601String()]), true);
        $payload = json_decode(base64_decode(strtr($envelope['payload'], '-_', '+/')), true);
        $payload['plan'] = 'lifetime';
        $payload['expires_at'] = null;
        $envelope['payload'] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.file'), ['entitlement' => json_encode($envelope)])
            ->assertSessionHasErrors(['entitlement' => 'Ce fichier de licence est invalide ou a été modifié : sa signature est refusée.']);

        $this->assertSame(CabinetStatus::PENDING, $this->cabinet->refresh()->status);
    }

    public function test_a_licence_file_for_another_local_cabinet_is_refused(): void
    {
        [$other] = $this->activeCabinetWithOwner('other-owner@example.com', CabinetStatus::PENDING);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.file'), ['entitlement' => $this->signedEntitlement(['owner_email' => 'other-owner@example.com'])])
            ->assertSessionHasErrors('entitlement');

        $this->assertSame(CabinetStatus::PENDING, $this->cabinet->refresh()->status);
        $this->assertSame(CabinetStatus::PENDING, $other->refresh()->status);
    }

    public function test_the_online_account_activates_and_links_the_poste_in_one_step(): void
    {
        $this->fakeCloud([
            'entitlement' => $this->validEntitlement(),
            'token' => 'remote-link-token',
            'account' => ['email' => self::OWNER, 'cabinet_name' => 'Cabinet en ligne'],
            'cabinet' => ['name' => 'Cabinet en ligne'],
        ]);

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.online-account'), [
                'online_email' => self::OWNER,
                'online_password' => 'secret-en-ligne',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame(CabinetStatus::ACTIVE, $this->cabinet->refresh()->status);
        $this->assertSame(5, $this->cabinet->seatLimit());

        $settings = app(MobileSyncSettings::class);
        $this->assertSame('remote-link-token', $settings->token());
        $this->assertTrue($settings->isConfigured());
        $this->assertSame((int) $this->cabinet->getKey(), $settings->cabinetId());

        Http::assertSent(fn (Request $request): bool => $request->url() === self::CLOUD.'/api/v1/desktop/activate'
            && $request['email'] === self::OWNER
            && $request['link'] === true);
    }

    public function test_the_online_account_must_be_the_cabinet_owner(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('cabinet.license.online-account'), [
                'online_email' => 'someone-else@example.com',
                'online_password' => 'secret',
            ])
            ->assertSessionHasErrors('online_email');

        Http::assertNothingSent();
    }

    public function test_a_fresh_desktop_imports_an_existing_online_cabinet(): void
    {
        $this->cabinet->owner()->first()->delete();
        $this->cabinet->delete();
        $this->assertSame(0, User::query()->count());

        $email = 'imported-owner@example.com';
        $this->fakeCloud([
            'entitlement' => $this->validEntitlement(['owner_email' => $email]),
            'token' => 'remote-link-token',
            'account' => ['email' => $email, 'cabinet_name' => 'Cabinet Ibn Sina'],
            'cabinet' => [
                'name' => 'Cabinet Ibn Sina',
                'specialization' => 'Médecine générale',
                'wilaya_code' => 31,
                'phone' => '0550000000',
                'owner_name' => 'Dr Amina Benali',
                'owner_email' => $email,
                'seat_limit' => 4,
            ],
        ], seats: ['owner_email' => $email, 'seat_limit' => 4]);

        $this->post(route('desktop.cabinet-login.store'), [
            'owner_email' => $email,
            'email' => $email,
            'password' => 'mot-de-passe-en-ligne',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $owner = User::query()->where('email', $email)->sole();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('Dr Amina Benali', $owner->name);

        $cabinet = $owner->cabinet;
        $this->assertSame('Cabinet Ibn Sina', $cabinet->name);
        $this->assertSame(31, $cabinet->wilaya_code);
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->status);
        $this->assertSame($owner->getKey(), $cabinet->owner_user_id);
        $this->assertSame(4, $cabinet->seatLimit());
        $this->assertTrue(app(MobileSyncSettings::class)->isConfigured());
    }

    public function test_a_fresh_desktop_import_reports_no_internet(): void
    {
        $this->cabinet->owner()->first()->delete();
        $this->cabinet->delete();
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->post(route('desktop.cabinet-login.store'), [
            'owner_email' => 'imported-owner@example.com',
            'email' => 'imported-owner@example.com',
            'password' => 'mot-de-passe-en-ligne',
        ])->assertSessionHasErrors(['email' => CloudActivationClient::OFFLINE_MESSAGE]);

        $this->assertGuest();
        $this->assertSame(0, Cabinet::query()->count());
    }
}
