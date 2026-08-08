<?php

namespace Tests\Feature\Api;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

class CabinetOnboardingTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_register_creates_a_pending_cabinet_and_owner(): void
    {
        $response = $this->postJson('/api/v1/cabinets/register', [
            'name' => 'Api Owner',
            'cabinet_name' => 'Cabinet API',
            'specialization' => 'Cardiologie',
            'phone' => '0555 98 76 54',
            'email' => 'api-owner@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['cabinet_id', 'status']);

        $owner = User::query()->where('email', 'api-owner@example.com')->sole();
        $this->assertNotNull($owner->approved_at);
        $this->assertSame('0555 98 76 54', $owner->doctorProfile?->phone);

        $cabinet = Cabinet::query()->sole();
        $this->assertSame(CabinetStatus::PENDING, $cabinet->status);
        $this->assertSame($response->json('cabinet_id'), $cabinet->getKey());
    }

    public function test_register_validation_fails_with_422(): void
    {
        $this->postJson('/api/v1/cabinets/register', [
            'name' => '',
            'email' => 'not-an-email',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_join_creates_a_pending_member(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('join-owner@example.com');

        $this->postJson('/api/v1/cabinets/join', [
            'name' => 'New Member',
            'email' => 'joiner@example.com',
            'owner_email' => 'join-owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(201)
            ->assertJsonPath('status', 'awaiting_approval');

        $member = User::query()->where('email', 'joiner@example.com')->sole();
        $this->assertSame($cabinet->getKey(), $member->cabinet_id);
        $this->assertNull($member->approved_at);
    }

    public function test_join_is_blocked_by_the_seat_limit(): void
    {
        [$cabinet] = $this->activeCabinetWithOwner('full-owner@example.com');

        // Owner is seat #1; fill the remaining two seats.
        User::factory()->count(2)->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->postJson('/api/v1/cabinets/join', [
            'name' => 'Fourth',
            'email' => 'fourth@example.com',
            'owner_email' => 'full-owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('owner_email');

        $this->assertDatabaseMissing('users', ['email' => 'fourth@example.com']);
    }

    public function test_join_unknown_owner_fails_with_422(): void
    {
        $this->postJson('/api/v1/cabinets/join', [
            'name' => 'Nobody',
            'email' => 'nobody@example.com',
            'owner_email' => 'ghost@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('owner_email');
    }
}
