<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-002 stage 2: a Cabinet Hub is this application running on one always-on
 * LAN machine, bound to exactly one cabinet, so the doctor and reception
 * desktops keep working with the Internet disconnected.
 *
 * These cover the boundary that makes that safe — one write authority, one
 * cabinet, and a Hub that fails closed instead of serving the wrong one.
 */
class CabinetHubModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function cabinet(string $name, CabinetStatus $status = CabinetStatus::ACTIVE): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => $name,
            'status' => $status,
            'activated_at' => $status === CabinetStatus::ACTIVE ? now() : null,
        ]);

        $owner = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $cabinet->refresh();
    }

    private function runAsHubFor(Cabinet $cabinet): void
    {
        config([
            'hub.enabled' => true,
            'hub.id' => 'hub-01HZ0000000000000000000000',
            'hub.cabinet_id' => $cabinet->getKey(),
            'hub.hostname' => 'hub-cabinet.drclick.local',
            'hub.tls_spki_sha256' => str_repeat('ab', 32),
        ]);
    }

    public function test_a_hosted_installation_is_unchanged_and_advertises_no_hub(): void
    {
        $this->getJson('/health')
            ->assertJsonPath('hub', null)
            ->assertJsonPath('application.name', 'Drclick');
    }

    public function test_a_hub_advertises_the_identity_a_desktop_pins_before_authenticating(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        // Read without any credentials or details key: a desktop has to know
        // which cabinet's Hub it reached before it can sign anybody in.
        $this->getJson('/health')
            ->assertJsonPath('hub.mode', 'hub')
            ->assertJsonPath('hub.protocol_version', 1)
            ->assertJsonPath('hub.hub_id', 'hub-01HZ0000000000000000000000')
            ->assertJsonPath('hub.cabinet_id', $cabinet->getKey())
            ->assertJsonPath('hub.hostname', 'hub-cabinet.drclick.local')
            ->assertJsonPath('hub.tls_spki_sha256', str_repeat('ab', 32))
            ->assertJsonPath('hub.ready', true)
            ->assertJsonPath('hub.reason', null);
    }

    public function test_the_hub_identity_carries_no_patient_or_member_data(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        $hub = $this->getJson('/health')->json('hub');

        $this->assertSame([
            'mode', 'protocol_version', 'hub_id', 'cabinet_id',
            'hostname', 'tls_spki_sha256', 'ready', 'reason',
        ], array_keys($hub));
        $this->assertStringNotContainsString('Houdaifa', json_encode($hub));
    }

    public function test_a_member_of_the_bound_cabinet_is_served_normally(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($member)->get('/dashboard')->assertOk();
    }

    public function test_a_member_of_another_cabinet_is_refused_and_signed_out(): void
    {
        $bound = $this->cabinet('Cabinet du Dr Houdaifa');
        $other = $this->cabinet('Cabinet voisin');
        $this->runAsHubFor($bound);

        $intruder = User::factory()->create([
            'cabinet_id' => $other->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($intruder)
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        // Blocking the request is not enough; the session has to end, or every
        // following request re-enters the same branch.
        $this->assertGuest();
    }

    public function test_a_platform_administrator_is_not_a_back_door_into_a_hub(): void
    {
        // A Hub is a cabinet's data plane, never a control plane.
        $bound = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($bound);

        $platformAdmin = User::factory()->create([
            'cabinet_id' => null,
            'is_platform_admin' => true,
            'approved_at' => now(),
        ]);

        $this->actingAs($platformAdmin)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_hub_bound_to_a_cabinet_that_is_not_in_the_database_fails_closed(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        // A restored or mismatched database. Serving whatever cabinet happens
        // to be present would be worse than serving nothing.
        $this->runAsHubFor($cabinet);
        config(['hub.cabinet_id' => $cabinet->getKey() + 9999]);

        $this->actingAs($member)->get('/dashboard')->assertStatus(503);
    }

    public function test_an_incompletely_configured_hub_fails_closed(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);
        config(['hub.id' => null]);

        $this->get('/login')->assertStatus(503);
    }

    public function test_a_broken_hub_still_answers_health_so_it_can_be_diagnosed(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);
        config(['hub.cabinet_id' => null]);

        $this->getJson('/health')
            ->assertJsonPath('hub.ready', false)
            ->assertJsonPath('hub.reason', 'hub_cabinet_missing');
    }

    public function test_a_hub_refuses_to_provision_a_second_cabinet(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        $this->post(route('register.store'), [
            'name' => 'Autre Docteur',
            'cabinet_name' => 'Deuxième cabinet',
            'specialization' => 'Pédiatrie',
            'phone' => '+213 555 12 34 56',
            'email' => 'autre@example.com',
            'wilaya' => 16,
            'password' => 'Cabinet-2026!secure',
            'password_confirmation' => 'Cabinet-2026!secure',
        ])->assertSessionHasErrors('cabinet_name');

        $this->assertSame(1, Cabinet::query()->count());
        $this->assertNull(User::query()->where('email', 'autre@example.com')->first());
    }

    public function test_the_registration_screen_on_a_hub_points_at_joining_instead(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        $this->get(route('register'))->assertRedirect(route('cabinet.join'));
    }

    public function test_reception_can_still_join_the_bound_cabinet_on_a_hub(): void
    {
        // This is the offline path to a second account: no Internet, no cloud,
        // just the reception desk asking the Hub for access.
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $owner = $cabinet->owner;
        $this->runAsHubFor($cabinet);

        $this->post(route('cabinet.join.store'), [
            'owner_email' => $owner->email,
            'name' => 'Réception',
            'email' => 'reception@example.com',
            'password' => 'Reception-2026!secure',
            'password_confirmation' => 'Reception-2026!secure',
        ])->assertSessionHasNoErrors();

        $reception = User::query()->where('email', 'reception@example.com')->first();
        $this->assertNotNull($reception);
        $this->assertSame($cabinet->getKey(), $reception->cabinet_id);
    }

    public function test_hub_status_command_reports_a_healthy_binding(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);

        $this->artisan('hub:status')
            ->expectsOutputToContain('Cabinet du Dr Houdaifa')
            ->assertExitCode(0);
    }

    public function test_hub_status_command_fails_on_a_broken_binding(): void
    {
        $cabinet = $this->cabinet('Cabinet du Dr Houdaifa');
        $this->runAsHubFor($cabinet);
        config(['hub.cabinet_id' => $cabinet->getKey() + 9999]);

        $this->artisan('hub:status')
            ->expectsOutputToContain('hub_cabinet_not_in_database')
            ->assertExitCode(1);
    }

    public function test_hub_status_command_reports_a_hosted_installation(): void
    {
        $this->artisan('hub:status')
            ->expectsOutputToContain('hosted')
            ->assertExitCode(0);
    }
}
