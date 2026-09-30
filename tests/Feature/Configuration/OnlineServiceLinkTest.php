<?php

namespace Tests\Feature\Configuration;

use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Configuration › Service en ligne: a local desktop signs in to the hosted
 * service once and keeps a token for seats, appointment sync and the AI
 * assistant.
 */
class OnlineServiceLinkTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private const ENDPOINT = 'https://online.drclick.test';

    private const PASSWORD = 'mot-de-passe-en-ligne-2026';

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'medismart.online_service.linkable' => true,
            'medismart.online_service.url' => self::ENDPOINT,
        ]);

        [$this->cabinet, $this->doctor] = $this->licensedCabinet('doctor@cabinet.test');
    }

    public function test_the_page_offers_to_link_an_unlinked_desktop(): void
    {
        $this->actingAs($this->doctor)
            ->get(route('app.configuration.online-service.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/OnlineService')
                ->where('available', true)
                ->where('link.linked', false)
                ->where('link.linkedElsewhere', false)
                ->where('link.endpoint', self::ENDPOINT)
                ->where('seats.limit', Cabinet::DEFAULT_SEATS)
                ->where('auth.user.can.linkOnlineService', true));
    }

    public function test_the_online_service_itself_has_nothing_to_link(): void
    {
        config(['medismart.online_service.linkable' => false]);
        Http::fake();

        $this->actingAs($this->doctor)
            ->get(route('app.configuration.online-service.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('available', false)
                ->where('auth.user.can.linkOnlineService', false));

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertForbidden();

        $this->assertNothingSentOnline();
    }

    public function test_an_assistant_cannot_link_the_desktop(): void
    {
        $assistant = User::factory()->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->actingAs($assistant)
            ->get(route('app.configuration.online-service.edit'))
            ->assertForbidden();
        $this->actingAs($assistant)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertForbidden();
    }

    public function test_linking_keeps_the_token_and_pulls_the_seats(): void
    {
        $this->fakeOnlineService(seatLimit: 4);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertRedirect(route('app.configuration.online-service.edit'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                'Poste relié au service en ligne. Votre cabinet dispose de 4 sièges.',
            );

        $settings = app(MobileSyncSettings::class);
        $this->assertSame(self::ENDPOINT, $settings->endpoint());
        $this->assertSame('remote-token', $settings->token());
        $this->assertSame('doctor@cabinet.test', $settings->accountEmail());
        $this->assertSame('Cabinet en ligne', $settings->cabinetName());
        $this->assertNotNull($settings->linkedAt());
        $this->assertSame((int) $this->cabinet->getKey(), $settings->cabinetId());
        $this->assertSame(4, $this->cabinet->fresh()->seat_limit);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/auth/token'
            && $request['email'] === 'doctor@cabinet.test'
            && $request['password'] === self::PASSWORD
            && str_starts_with((string) $request['device_name'], 'Poste Drclick'));

        // The password was used once and is kept nowhere on this desktop.
        foreach (ApplicationSetting::query()->get() as $setting) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) $setting->plain_value);
        }
        $audit = AuditLog::query()->where('action', 'online_service.linked')->sole();
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($audit->metadata));
        $this->assertSame('online.drclick.test', $audit->metadata['endpoint_host']);

        $this->actingAs($this->doctor)
            ->get(route('app.configuration.online-service.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('link.linked', true)
                ->where('link.accountEmail', 'doctor@cabinet.test')
                ->where('link.cabinetName', 'Cabinet en ligne')
                ->where('seats.limit', 4)
                ->where('seats.canCheckOnline', true));
    }

    public function test_wrong_credentials_are_reported(): void
    {
        Http::fake([self::ENDPOINT.'/api/v1/auth/token' => Http::response([
            'message' => 'Ces identifiants ne correspondent à aucun compte.',
            'errors' => ['email' => ['Ces identifiants ne correspondent à aucun compte.']],
        ], 422)]);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors([
                'email' => 'Adresse e-mail ou mot de passe incorrect sur le service en ligne.',
            ]);

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    public function test_a_blocked_online_account_explains_why(): void
    {
        Http::fake([self::ENDPOINT.'/api/v1/auth/token' => Http::response([
            'message' => 'Votre cabinet est actuellement suspendu. Contactez le support Drclick.',
            'reason' => 'cabinet_suspended',
        ], 403)]);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors([
                'email' => 'Votre cabinet est actuellement suspendu. Contactez le support Drclick.',
            ]);

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    public function test_an_unreachable_service_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('endpoint');

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    public function test_an_address_that_is_not_the_online_service_is_refused(): void
    {
        Http::fake(['*' => Http::response('<html>Bienvenue</html>', 200)]);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('endpoint');

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    public function test_only_a_plain_https_address_is_accepted(): void
    {
        Http::fake();

        foreach ([
            'http://online.drclick.test',
            'https://doctor:secret@online.drclick.test',
            'https://online.drclick.test/?next=evil',
            'online.drclick.test',
        ] as $endpoint) {
            $this->actingAs($this->doctor)
                ->post(route('app.configuration.online-service.store'), $this->credentials($endpoint))
                ->assertSessionHasErrors('endpoint');
        }

        $this->assertNothingSentOnline();
    }

    public function test_an_account_from_another_cabinet_is_not_kept(): void
    {
        // A desktop holding two cabinets can only match the answer by owner.
        $this->licensedCabinet('other@cabinet.test');
        $this->fakeOnlineService(seatLimit: 9, ownerEmail: 'other@cabinet.test');

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('email');

        $this->assertNull(app(MobileSyncSettings::class)->token());
        $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
        // The token minted for nothing is revoked on the online service.
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer remote-token'));
    }

    public function test_an_account_from_another_cabinet_is_not_kept_on_a_single_cabinet_desktop(): void
    {
        // The normal desktop: one cabinet, so only the owner can match.
        $this->fakeOnlineService(seatLimit: 50, ownerEmail: 'someone-else@other-cabinet.test');

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors([
                'email' => 'Ce compte en ligne appartient à un autre cabinet que celui de ce poste. '
                    .'Utilisez le compte en ligne de ce cabinet, dont le titulaire a la même adresse e-mail que sur ce poste.',
            ]);

        $this->assertNull(app(MobileSyncSettings::class)->token());
        $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer remote-token'));
    }

    public function test_the_link_is_refused_when_the_service_cannot_confirm_the_cabinet(): void
    {
        $statuses = [404, 500, 403];
        $seats = Http::sequence();

        foreach ($statuses as $status) {
            $seats->push(['message' => 'Ce compte n’est rattaché à aucun cabinet.', 'reason' => 'no_cabinet'], $status);
        }

        Http::fake([
            self::ENDPOINT.'/api/v1/auth/token' => Http::response(['token' => 'remote-token', 'user' => ['email' => 'doctor@cabinet.test']]),
            self::ENDPOINT.'/api/v1/cabinet/seats' => $seats,
            self::ENDPOINT.'/api/v1/auth/logout' => Http::response(['message' => 'Déconnexion réussie.']),
        ]);

        foreach ($statuses as $status) {
            $this->actingAs($this->doctor)
                ->post(route('app.configuration.online-service.store'), $this->credentials())
                ->assertSessionHasErrors('email');

            $settings = app(MobileSyncSettings::class);
            $this->assertNull($settings->token(), "Kept after a {$status}.");
            $this->assertFalse($settings->isConfigured());
            $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
        }

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/auth/logout');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'online_service.linked']);
    }

    public function test_a_connection_lost_before_the_cabinet_is_confirmed_leaves_the_desktop_unlinked(): void
    {
        Http::fake([
            self::ENDPOINT.'/api/v1/auth/token' => Http::response(['token' => 'remote-token', 'user' => ['email' => 'doctor@cabinet.test']]),
            self::ENDPOINT.'/api/v1/cabinet/seats' => fn () => throw new ConnectionException('offline'),
            self::ENDPOINT.'/api/v1/auth/logout' => Http::response(['message' => 'Déconnexion réussie.']),
        ]);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('email');

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    public function test_a_platform_administrator_account_cannot_link_a_desktop(): void
    {
        Http::fake([
            self::ENDPOINT.'/api/v1/auth/token' => Http::response([
                'token' => 'admin-token',
                'user' => ['email' => 'support@drclick.test', 'is_platform_admin' => true, 'cabinet' => null],
            ]),
            self::ENDPOINT.'/api/v1/auth/logout' => Http::response(['message' => 'Déconnexion réussie.']),
        ]);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('email');

        $this->assertNull(app(MobileSyncSettings::class)->token());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer admin-token'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/cabinet/seats'));
    }

    public function test_the_link_serves_only_the_cabinet_it_was_made_for(): void
    {
        [$other, $otherOwner] = $this->licensedCabinet('other@cabinet.test');
        $this->fakeOnlineService(seatLimit: 4);

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $this->cabinet->getKey(), app(MobileSyncSettings::class)->cabinetId());

        // The other cabinet of this computer sees that the desktop is linked,
        // not whose account it is, and cannot undo the link.
        $this->actingAs($otherOwner)
            ->get(route('app.configuration.online-service.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('link.linked', false)
                ->where('link.linkedElsewhere', true)
                ->where('link.accountEmail', null)
                ->where('seats.canCheckOnline', false));

        $this->actingAs($otherOwner)
            ->delete(route('app.configuration.online-service.destroy'))
            ->assertForbidden();

        $this->actingAs($otherOwner)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors([
                'endpoint' => 'Ce poste est déjà relié au service en ligne pour un autre cabinet de cet ordinateur.',
            ]);

        $this->assertSame('remote-token', app(MobileSyncSettings::class)->token());
        $this->assertSame(Cabinet::DEFAULT_SEATS, $other->fresh()->seat_limit);
    }

    public function test_a_linked_desktop_must_be_unlinked_before_linking_again(): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'existing-token', 'doctor@cabinet.test');
        Http::fake();

        $this->actingAs($this->doctor)
            ->post(route('app.configuration.online-service.store'), $this->credentials())
            ->assertSessionHasErrors('endpoint');

        $this->assertSame('existing-token', app(MobileSyncSettings::class)->token());
        $this->assertNothingSentOnline();
    }

    public function test_unlinking_revokes_the_token_and_forgets_the_link(): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'existing-token', 'doctor@cabinet.test');
        $this->cabinet->forceFill(['seat_limit' => 5])->save();
        Http::fake([self::ENDPOINT.'/api/v1/auth/logout' => Http::response(['message' => 'Déconnexion réussie.'])]);

        $this->actingAs($this->doctor)
            ->delete(route('app.configuration.online-service.destroy'))
            ->assertRedirect(route('app.configuration.online-service.edit'));

        $settings = app(MobileSyncSettings::class);
        $this->assertNull($settings->token());
        $this->assertNull($settings->accountEmail());
        $this->assertFalse($settings->isConfigured());
        // The address is remembered for next time; the seats already received stay.
        $this->assertSame(self::ENDPOINT, $settings->endpoint());
        $this->assertSame(5, $this->cabinet->fresh()->seat_limit);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer existing-token'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'online_service.unlinked']);
    }

    public function test_unlinking_while_offline_still_forgets_the_link(): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'existing-token');
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->actingAs($this->doctor)
            ->delete(route('app.configuration.online-service.destroy'))
            ->assertRedirect();

        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    /**
     * @return array{0: Cabinet, 1: User}
     */
    private function licensedCabinet(string $ownerEmail): array
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner($ownerEmail);

        $license = License::query()->create([
            'license_id' => 'CAB-'.$cabinet->getKey().'-LINK',
            'product' => 'medismart-desktop',
            'edition' => 'hosted',
            'plan' => LicensePlan::LIFETIME,
            'customer_id' => (string) $cabinet->getKey(),
            'status' => 'active',
            'issued_at' => now(),
        ]);
        $cabinet->forceFill(['license_id' => $license->getKey()])->save();

        return [$cabinet->fresh(), $owner];
    }

    private function fakeOnlineService(
        int $seatLimit = 2,
        string $ownerEmail = 'doctor@cabinet.test',
        int $seatsStatus = 200,
    ): void {
        Http::fake([
            self::ENDPOINT.'/api/v1/auth/token' => Http::response([
                'token' => 'remote-token',
                'user' => [
                    'email' => 'doctor@cabinet.test',
                    'cabinet' => ['id' => 77, 'name' => 'Cabinet en ligne'],
                ],
            ]),
            self::ENDPOINT.'/api/v1/cabinet/seats' => $seatsStatus === 200
                ? Http::response(['data' => [
                    'seat_limit' => $seatLimit,
                    'seats_in_use' => 1,
                    'owner_email' => $ownerEmail,
                ]])
                : Http::response(['message' => 'Not Found'], $seatsStatus),
            self::ENDPOINT.'/api/v1/auth/logout' => Http::response(['message' => 'Déconnexion réussie.']),
        ]);
    }

    /**
     * Rendering a page may call the local SSR server; only requests to the
     * online service matter here.
     */
    private function assertNothingSentOnline(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'online.drclick.test'));
    }

    /** @return array<string, string> */
    private function credentials(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'email' => 'doctor@cabinet.test',
            'password' => self::PASSWORD,
        ];
    }
}
