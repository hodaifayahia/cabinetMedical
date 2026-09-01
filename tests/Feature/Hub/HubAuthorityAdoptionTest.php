<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\HubAuthority;
use App\Models\User;
use App\Services\Hub\HubAdoptionService;
use App\Services\Hub\HubMode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * The authority epoch and the offline break-glass (ADR-003).
 *
 * A Hub holds clinical write authority only because an operator adopted it.
 * That record lives in the database, so a backup restored onto replacement
 * hardware carries it along and the new machine can tell that the cabinet's
 * authority still belongs to the box it is replacing.
 *
 * Every test runs with outbound HTTP blocked: recovery has to work in a cabinet
 * that has never had an Internet connection.
 */
class HubAuthorityAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_HUB = 'hub-original-0001';

    private const REPLACEMENT_HUB = 'hub-replacement-0002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Inertia server-side rendering calls a local Node process. It is a
        // rendering detail, not an Internet dependency, but it is still an
        // outbound HTTP call and would mask the guard below.
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function cabinet(): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet du Dr Houdaifa',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $cabinet->refresh();
    }

    private function runAs(string $hubId, Cabinet $cabinet): void
    {
        config([
            'hub.enabled' => true,
            'hub.id' => $hubId,
            'hub.cabinet_id' => $cabinet->getKey(),
        ]);
    }

    private function adoption(): HubAdoptionService
    {
        return app(HubAdoptionService::class);
    }

    private function hub(): HubMode
    {
        return app(HubMode::class);
    }

    public function test_a_configured_but_unadopted_hub_holds_no_authority_and_serves_nobody(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);

        $this->assertSame(
            HubMode::MISCONFIGURED_REASON_NOT_ADOPTED,
            $this->hub()->misconfigurationReason(),
        );

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $this->actingAs($member)->get('/dashboard')->assertStatus(503);
    }

    public function test_adopting_a_fresh_hub_starts_at_epoch_one(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);

        $this->assertTrue($this->adoption()->wouldProvision());

        $authority = $this->adoption()->adopt();

        $this->assertSame(1, $authority->authority_epoch);
        $this->assertSame(self::ORIGINAL_HUB, $authority->hub_id);
        $this->assertSame(HubAuthority::REASON_PROVISIONED, $authority->adopted_reason);
        $this->assertNull($authority->previous_hub_id);
        $this->assertNull($this->hub()->misconfigurationReason());
    }

    public function test_a_restored_backup_on_new_hardware_refuses_to_serve_until_adopted(): void
    {
        // This is the scenario the whole design exists for. The original Hub is
        // adopted, its database is restored onto a replacement appliance, and
        // that appliance has a different identity.
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->runAs(self::REPLACEMENT_HUB, $cabinet);

        $this->assertSame(
            HubMode::MISCONFIGURED_REASON_DISPLACED,
            $this->hub()->misconfigurationReason(),
            'a restored clone must not silently become a second write authority',
        );

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $this->actingAs($member)->get('/dashboard')->assertStatus(503);
    }

    public function test_the_replacement_can_be_adopted_on_the_lan_with_no_control_plane(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->runAs(self::REPLACEMENT_HUB, $cabinet);
        $authority = $this->adoption()->adopt();

        $this->assertSame(2, $authority->authority_epoch, 'taking over must raise the epoch');
        $this->assertSame(self::REPLACEMENT_HUB, $authority->hub_id);
        $this->assertSame(self::ORIGINAL_HUB, $authority->previous_hub_id);
        $this->assertSame(HubAuthority::REASON_REPLACEMENT, $authority->adopted_reason);
        $this->assertNull($this->hub()->misconfigurationReason());

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $this->actingAs($member)->get('/dashboard')->assertOk();
    }

    public function test_the_displaced_hub_is_fenced_the_moment_the_replacement_is_adopted(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->runAs(self::REPLACEMENT_HUB, $cabinet);
        $this->adoption()->adopt();

        // The old box is plugged back in on the same LAN, still believing it
        // owns the cabinet. It shares this database in the test, which is the
        // strongest form of the check: even seeing the newer record, it must
        // refuse rather than compete.
        $this->runAs(self::ORIGINAL_HUB, $cabinet);

        $this->assertSame(
            HubMode::MISCONFIGURED_REASON_DISPLACED,
            $this->hub()->misconfigurationReason(),
        );
    }

    public function test_authority_never_moves_without_being_asked_twice(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already holds authority');
        $this->adoption()->adopt();
    }

    public function test_an_unbound_or_anonymous_hub_cannot_adopt_anything(): void
    {
        $cabinet = $this->cabinet();

        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        config(['hub.id' => null]);
        try {
            $this->adoption()->adopt();
            $this->fail('an anonymous Hub must not be able to take authority');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('no identity', $exception->getMessage());
        }

        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        config(['hub.cabinet_id' => $cabinet->getKey() + 9999]);
        $this->expectExceptionMessage('bound cabinet does not exist');
        $this->adoption()->adopt();
    }

    public function test_a_hosted_installation_cannot_adopt_authority(): void
    {
        $this->cabinet();
        config(['hub.enabled' => false]);

        $this->expectExceptionMessage('not a Cabinet Hub');
        $this->adoption()->adopt();
    }

    public function test_the_epoch_is_advertised_so_a_desktop_can_refuse_to_go_backwards(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->getJson('/health')->assertJsonPath('hub.authority_epoch', 1);

        $this->runAs(self::REPLACEMENT_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->getJson('/health')->assertJsonPath('hub.authority_epoch', 2);
    }

    public function test_a_displaced_hub_still_answers_health_so_it_can_be_diagnosed(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();
        $this->runAs(self::REPLACEMENT_HUB, $cabinet);

        $this->getJson('/health')
            ->assertJsonPath('hub.ready', false)
            ->assertJsonPath('hub.reason', 'hub_displaced_by_another');
    }

    public function test_the_adopt_command_describes_the_takeover_before_doing_it(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();
        $this->runAs(self::REPLACEMENT_HUB, $cabinet);

        // Without --confirm nothing may change, and the operator must be told
        // plainly what taking over would mean.
        $this->artisan('hub:adopt')
            ->expectsOutputToContain('TAKE OVER')
            ->assertExitCode(0);

        $this->assertSame(1, HubAuthority::query()->firstOrFail()->authority_epoch);
        $this->assertSame(self::ORIGINAL_HUB, HubAuthority::query()->firstOrFail()->hub_id);
    }

    public function test_the_adopt_command_performs_the_takeover_when_confirmed(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();
        $this->runAs(self::REPLACEMENT_HUB, $cabinet);

        $this->artisan('hub:adopt', ['--confirm' => true])->assertExitCode(0);

        $authority = HubAuthority::query()->firstOrFail();
        $this->assertSame(self::REPLACEMENT_HUB, $authority->hub_id);
        $this->assertSame(2, $authority->authority_epoch);
    }

    public function test_the_adopt_command_is_idempotent_for_the_holder(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->artisan('hub:adopt', ['--confirm' => true])
            ->expectsOutputToContain('already holds authority')
            ->assertExitCode(0);

        $this->assertSame(1, HubAuthority::query()->firstOrFail()->authority_epoch);
    }

    public function test_the_adopt_command_refuses_a_cabinet_that_is_not_in_this_database(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        config(['hub.cabinet_id' => $cabinet->getKey() + 9999]);

        $this->artisan('hub:adopt', ['--confirm' => true])->assertExitCode(1);
        $this->assertSame(0, HubAuthority::query()->count());
    }

    public function test_adoption_is_audited_so_a_takeover_is_never_silent(): void
    {
        $cabinet = $this->cabinet();
        $this->runAs(self::ORIGINAL_HUB, $cabinet);
        $this->adoption()->adopt();
        $this->runAs(self::REPLACEMENT_HUB, $cabinet);
        $this->adoption()->adopt();

        $this->assertDatabaseHas('audit_logs', ['action' => 'hub.authority_adopted']);
        $this->assertSame(2, AuditLog::query()->where('action', 'hub.authority_adopted')->count());
    }
}
