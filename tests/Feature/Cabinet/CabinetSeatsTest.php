<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Seats are sold per cabinet: the admin panel sets how many accounts each
 * doctor may hold, the staff screen enforces it, and a local desktop picks up
 * a new allowance from the online service once it is connected.
 */
class CabinetSeatsTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private const ENDPOINT = 'https://sync.drclick.test';

    private const SEATS_URL = self::ENDPOINT.'/api/v1/cabinet/seats';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_new_cabinet_lets_the_doctor_add_exactly_one_user(): void
    {
        [$cabinet, $owner] = $this->licensedCabinet('doctor@seats.test');

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->seatLimit());

        $this->actingAs($owner)
            ->post(route('app.staff.store'), $this->newStaff('first@seats.test'))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('app.staff.store'), $this->newStaff('second@seats.test'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('users', ['email' => 'first@seats.test']);
        $this->assertDatabaseMissing('users', ['email' => 'second@seats.test']);
    }

    public function test_the_staff_screen_shows_the_cabinets_seats(): void
    {
        [, $owner] = $this->licensedCabinet('doctor@seats.test');

        $this->actingAs($owner)
            ->get(route('app.staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Index')
                ->where('seats.used', 1)
                ->where('seats.limit', 2)
                ->where('seats.remaining', 1)
                ->where('seats.canCheckOnline', false)
                ->where('seats.syncedAt', null));
    }

    public function test_the_activation_code_modal_sets_the_seats_and_their_price(): void
    {
        Mail::fake();
        $cabinet = $this->pendingCabinet('pending@seats.test');
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(ListCabinets::class)
            ->callTableAction('issueLicenseCode', $cabinet, [
                'plan' => LicensePlan::TRIAL->value,
                'seat_limit' => 4,
                'seat_price' => 1500,
            ])
            ->assertHasNoTableActionErrors();

        $cabinet->refresh();
        $this->assertSame(4, $cabinet->seat_limit);
        $this->assertSame(1500, $cabinet->seat_price);

        $audit = AuditLog::query()->where('action', 'cabinet.seats_updated')->sole();
        $this->assertSame(Cabinet::DEFAULT_SEATS, $audit->metadata['previous_seat_limit']);
        $this->assertSame(4, $audit->metadata['seat_limit']);
    }

    public function test_the_activation_code_modal_keeps_the_seats_the_admin_did_not_change(): void
    {
        Mail::fake();
        $cabinet = $this->pendingCabinet('pending@seats.test');
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(ListCabinets::class)
            ->callTableAction('issueLicenseCode', $cabinet, [
                'plan' => LicensePlan::TRIAL->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'cabinet.seats_updated']);
    }

    public function test_the_seats_action_raises_the_allowance_of_an_active_cabinet(): void
    {
        [$cabinet, $owner] = $this->licensedCabinet('doctor@seats.test');
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(ListCabinets::class)
            ->callTableAction('manageSeats', $cabinet, [
                'seat_limit' => 3,
                'seat_price' => 2000,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(3, $cabinet->fresh()->seat_limit);
        $this->assertSame(2000, $cabinet->fresh()->seat_price);

        // Two more colleagues now fit, and not a third.
        $this->actingAs($owner);
        $this->post(route('app.staff.store'), $this->newStaff('a@seats.test'))->assertSessionHasNoErrors();
        $this->post(route('app.staff.store'), $this->newStaff('b@seats.test'))->assertSessionHasNoErrors();
        $this->post(route('app.staff.store'), $this->newStaff('c@seats.test'))->assertSessionHasErrors('email');
    }

    public function test_clearing_the_price_in_the_seats_action_removes_it(): void
    {
        [$cabinet] = $this->licensedCabinet('doctor@seats.test');
        $cabinet->forceFill(['seat_price' => 2000])->save();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(ListCabinets::class)
            ->callTableAction('manageSeats', $cabinet, [
                'seat_limit' => 2,
                'seat_price' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNull($cabinet->fresh()->seat_price);
    }

    public function test_the_seats_action_refuses_an_out_of_range_allowance(): void
    {
        [$cabinet] = $this->licensedCabinet('doctor@seats.test');
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        foreach ([0, Cabinet::MAX_GRANTABLE_SEATS + 1] as $seatLimit) {
            Livewire::test(ListCabinets::class)
                ->callTableAction('manageSeats', $cabinet, ['seat_limit' => $seatLimit])
                ->assertHasTableActionErrors(['seat_limit']);
        }

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
    }

    public function test_only_a_platform_admin_may_change_seats(): void
    {
        [$cabinet, $owner] = $this->licensedCabinet('doctor@seats.test');
        $this->actingAs($owner);

        $this->expectException(AuthorizationException::class);

        app(CabinetFulfillmentService::class)->updateSeatAllowance($cabinet, 10, null);
    }

    public function test_the_online_service_tells_a_desktop_its_seats(): void
    {
        [$cabinet, $owner] = $this->licensedCabinet('doctor@seats.test');
        $cabinet->forceFill(['seat_limit' => 4])->save();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/cabinet/seats')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'seat_limit' => 4,
                    'seats_in_use' => 1,
                    'owner_email' => 'doctor@seats.test',
                ],
            ]);
    }

    public function test_the_seat_endpoint_needs_a_token(): void
    {
        $this->getJson('/api/v1/cabinet/seats')->assertUnauthorized();
    }

    public function test_a_desktop_uses_seats_granted_while_it_was_offline(): void
    {
        [$cabinet, $owner] = $this->fullLinkedCabinet('doctor@seats.test');
        Http::fake([self::SEATS_URL => $this->remoteSeats(3, 'doctor@seats.test')]);

        $this->actingAs($owner)
            ->post(route('app.staff.store'), $this->newStaff('third@seats.test'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'third@seats.test']);
        $cabinet->refresh();
        $this->assertSame(3, $cabinet->seat_limit);
        $this->assertNotNull($cabinet->seat_limit_synced_at);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEATS_URL
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_an_offline_desktop_keeps_the_seats_it_last_knew(): void
    {
        [$cabinet, $owner] = $this->fullLinkedCabinet('doctor@seats.test');
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->actingAs($owner)
            ->post(route('app.staff.store'), $this->newStaff('third@seats.test'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'third@seats.test']);
        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
    }

    public function test_a_desktop_with_a_free_seat_does_not_ask_the_online_service(): void
    {
        [, $owner] = $this->licensedCabinet('doctor@seats.test');
        $this->linkToOnlineService();
        Http::fake();

        $this->actingAs($owner)
            ->post(route('app.staff.store'), $this->newStaff('first@seats.test'))
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
    }

    public function test_the_staff_screen_checks_the_seats_online(): void
    {
        [$cabinet, $owner] = $this->fullLinkedCabinet('doctor@seats.test');
        Http::fake([self::SEATS_URL => $this->remoteSeats(5, 'doctor@seats.test')]);

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message', 'Votre cabinet dispose maintenant de 5 sièges.')
            ->assertJsonPath('seats.used', 2)
            ->assertJsonPath('seats.limit', 5)
            ->assertJsonPath('seats.remaining', 3)
            ->assertJsonPath('seats.canCheckOnline', true);

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->assertSame(5, $cabinet->fresh()->seat_limit);
        $this->assertSame(1, AuditLog::query()->where('action', 'cabinet.seats_synced')->count());
    }

    public function test_the_staff_screen_says_when_the_desktop_is_offline(): void
    {
        [, $owner] = $this->fullLinkedCabinet('doctor@seats.test');
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertStatus(503)
            ->assertJsonPath('offline', true)
            ->assertJsonPath('seats.limit', Cabinet::DEFAULT_SEATS);
    }

    public function test_seats_granted_to_another_cabinet_are_never_applied(): void
    {
        [$mine, $owner] = $this->licensedCabinet('mine@seats.test');
        [$other] = $this->licensedCabinet('other@seats.test');
        $this->linkToOnlineService();
        Http::fake([self::SEATS_URL => $this->remoteSeats(9, 'other@seats.test')]);

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertStatus(503)
            ->assertJsonPath('offline', false);

        $this->assertSame(Cabinet::DEFAULT_SEATS, $mine->fresh()->seat_limit);
        $this->assertSame(Cabinet::DEFAULT_SEATS, $other->fresh()->seat_limit);
    }

    public function test_a_single_cabinet_desktop_never_takes_another_cabinets_seats(): void
    {
        [$mine, $owner] = $this->fullLinkedCabinet('mine@seats.test');
        Http::fake([self::SEATS_URL => $this->remoteSeats(50, 'someone-else@other-cabinet.test')]);

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertStatus(503)
            ->assertJsonPath('offline', false);

        $this->artisan('drclick:sync-seats')->assertFailed();

        $this->assertSame(Cabinet::DEFAULT_SEATS, $mine->fresh()->seat_limit);
        $this->assertNull($mine->fresh()->seat_limit_synced_at);
    }

    public function test_seats_are_checked_only_for_the_cabinet_the_desktop_was_linked_for(): void
    {
        [$mine] = $this->licensedCabinet('mine@seats.test');
        [$other, $otherOwner] = $this->licensedCabinet('other@seats.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'test-token', cabinetId: (int) $mine->getKey());
        Http::fake([self::SEATS_URL => $this->remoteSeats(9, 'other@seats.test')]);

        $this->actingAs($otherOwner)
            ->get(route('app.staff.index'))
            ->assertInertia(fn (Assert $page) => $page->where('seats.canCheckOnline', false));

        $this->actingAs($otherOwner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertStatus(503);

        $this->assertSame(Cabinet::DEFAULT_SEATS, $other->fresh()->seat_limit);
        $this->assertSame(Cabinet::DEFAULT_SEATS, $mine->fresh()->seat_limit);
    }

    public function test_the_online_service_does_not_give_a_platform_admin_token_to_a_desktop(): void
    {
        [$cabinet] = $this->licensedCabinet('doctor@seats.test');
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'cabinet_id' => $cabinet->getKey(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/cabinet/seats')
            ->assertForbidden()
            ->assertJsonPath('reason', 'platform_admin');
    }

    public function test_a_malformed_answer_leaves_the_seats_alone(): void
    {
        [$cabinet, $owner] = $this->fullLinkedCabinet('doctor@seats.test');
        Http::fake([self::SEATS_URL => Http::response(['data' => [
            'seat_limit' => Cabinet::MAX_GRANTABLE_SEATS + 1,
            'owner_email' => 'doctor@seats.test',
        ]])]);

        $this->actingAs($owner)
            ->postJson(route('app.staff.seats.refresh'))
            ->assertStatus(503);

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
        $this->assertNull($cabinet->fresh()->seat_limit_synced_at);
    }

    public function test_the_scheduled_refresh_applies_new_seats(): void
    {
        [$cabinet] = $this->licensedCabinet('doctor@seats.test');
        $this->linkToOnlineService();
        Http::fake([self::SEATS_URL => $this->remoteSeats(6, 'doctor@seats.test')]);

        $this->artisan('drclick:sync-seats')->assertSuccessful();

        $this->assertSame(6, $cabinet->fresh()->seat_limit);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cabinet.seats_synced']);
    }

    public function test_the_scheduled_refresh_is_quiet_when_offline(): void
    {
        [$cabinet] = $this->licensedCabinet('doctor@seats.test');
        $this->linkToOnlineService();
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->artisan('drclick:sync-seats')->assertSuccessful();

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
    }

    public function test_the_scheduled_refresh_does_nothing_where_there_is_nothing_to_ask(): void
    {
        $this->licensedCabinet('doctor@seats.test');
        Http::fake();

        $this->artisan('drclick:sync-seats')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * An active cabinet on a hosted lifetime licence, which is what lets its
     * owner add colleagues at all.
     *
     * @return array{0: Cabinet, 1: User}
     */
    private function licensedCabinet(string $ownerEmail): array
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner($ownerEmail);

        $license = License::query()->create([
            'license_id' => 'CAB-'.$cabinet->getKey().'-SEATS',
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

    /**
     * A desktop cabinet using both of its default seats, linked to the
     * online service.
     *
     * @return array{0: Cabinet, 1: User}
     */
    private function fullLinkedCabinet(string $ownerEmail): array
    {
        [$cabinet, $owner] = $this->licensedCabinet($ownerEmail);
        User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $this->linkToOnlineService();

        return [$cabinet, $owner];
    }

    private function pendingCabinet(string $ownerEmail): Cabinet
    {
        $owner = User::factory()->create(['email' => $ownerEmail]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$ownerEmail,
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        return $cabinet;
    }

    private function linkToOnlineService(): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, 'test-token');
    }

    private function remoteSeats(int $seatLimit, string $ownerEmail): mixed
    {
        return Http::response([
            'data' => [
                'seat_limit' => $seatLimit,
                'seats_in_use' => 2,
                'owner_email' => $ownerEmail,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function newStaff(string $email): array
    {
        return [
            'name' => 'Assistante',
            'email' => $email,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
            'role' => RoleName::ASSISTANT->value,
            'assigned_to_cabinet' => true,
        ];
    }
}
