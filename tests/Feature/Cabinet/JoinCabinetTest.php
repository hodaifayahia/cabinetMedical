<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinCabinetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function activeCabinetWithOwner(string $ownerEmail): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Actif',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        $owner = User::factory()->create([
            'email' => $ownerEmail,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $owner->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [$cabinet, $owner];
    }

    public function test_joining_creates_a_pending_member(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('owner@example.com');

        $this->post(route('cabinet.join.store'), [
            'name' => 'New Member',
            'email' => 'member@example.com',
            'owner_email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('login'));

        $member = User::query()->where('email', 'member@example.com')->sole();
        $this->assertSame($cabinet->getKey(), $member->cabinet_id);
        $this->assertNull($member->approved_at);
        $this->assertTrue($member->getRoleNames()->isEmpty());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cabinet.join_requested',
            'cabinet_id' => $cabinet->getKey(),
        ]);
    }

    public function test_joining_an_unknown_owner_fails(): void
    {
        $this->post(route('cabinet.join.store'), [
            'name' => 'New Member',
            'email' => 'member@example.com',
            'owner_email' => 'nobody@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('owner_email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_joining_with_an_ordinary_members_email_fails(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('owner@example.com');

        User::factory()->create([
            'email' => 'existing-member@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->post(route('cabinet.join.store'), [
            'name' => 'New Member',
            'email' => 'new-member@example.com',
            'owner_email' => 'existing-member@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('users', ['email' => 'new-member@example.com']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_seat_limit_blocks_a_fourth_member(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('owner@example.com');

        // Owner is seat #1; add two more approved members to fill 3 seats.
        User::factory()->count(2)->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->assertSame(Cabinet::MAX_SEATS, $cabinet->fresh()->seatsInUse());

        $this->post(route('cabinet.join.store'), [
            'name' => 'Fourth',
            'email' => 'fourth@example.com',
            'owner_email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('users', ['email' => 'fourth@example.com']);
    }

    public function test_pending_members_count_toward_the_seat_limit(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('owner@example.com');

        // Owner is seat #1; pending requests reserve the other two seats.
        User::factory()->count(2)->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        $this->assertSame(Cabinet::MAX_SEATS, $cabinet->fresh()->seatsInUse());

        $this->post(route('cabinet.join.store'), [
            'name' => 'Fourth',
            'email' => 'fourth@example.com',
            'owner_email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('users', ['email' => 'fourth@example.com']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_owner_can_approve_a_pending_member(): void
    {
        [$cabinet, $owner] = $this->activeCabinetWithOwner('owner@example.com');

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        $this->actingAs($owner)
            ->post(route('app.staff.pending.approve', $member), [
                'role' => RoleName::RECEPTIONIST->value,
            ])
            ->assertRedirect();

        $member->refresh();
        $this->assertNotNull($member->approved_at);
        $this->assertTrue($member->hasRole(RoleName::RECEPTIONIST->value));
    }

    public function test_pending_member_is_gated_until_approved(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('owner@example.com');

        $member = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertRedirect(route('cabinet.awaiting-approval'));
    }
}
