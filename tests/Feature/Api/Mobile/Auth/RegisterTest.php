<?php

namespace Tests\Feature\Api\Mobile\Auth;

use App\Enums\RoleName;
use App\Models\Baladiya;
use App\Models\User;
use App\Models\Wilaya;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '0551234567',
            'password' => 'motdepasse-solide',
            'first_name' => 'Amine',
            'last_name' => 'Benali',
            'gender' => 'male',
            'date_of_birth' => '1990-04-15',
            'wilaya_code' => 16,
            'terms_accepted' => true,
        ], $overrides);
    }

    public function test_registration_creates_a_patient_account_with_profile_and_a_working_token(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);
        $baladiya = Baladiya::factory()->create(['wilaya_code' => $wilaya->code, 'name_fr' => 'Bab El Oued']);

        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'amine@example.com',
            'baladiya_id' => $baladiya->getKey(),
            'device_name' => 'Pixel 8',
        ]));

        $response->assertCreated()
            ->assertJsonPath('role', 'patient')
            ->assertJsonPath('user.phone', '0551234567')
            ->assertJsonPath('user.email', 'amine@example.com')
            ->assertJsonPath('user.role', 'patient')
            ->assertJsonPath('user.first_name', 'Amine')
            ->assertJsonPath('user.last_name', 'Benali')
            ->assertJsonPath('user.gender', 'male')
            ->assertJsonPath('user.date_of_birth', '1990-04-15')
            ->assertJsonPath('user.wilaya.code', 16)
            ->assertJsonPath('user.wilaya.name_fr', 'Alger')
            ->assertJsonPath('user.baladiya.name_fr', 'Bab El Oued');

        $user = User::query()->where('phone', '0551234567')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleName::PATIENT->value));
        $this->assertNull($user->cabinet_id);
        $this->assertNotNull($user->approved_at);
        $this->assertCount(0, $user->getAllPermissions());
        $this->assertDatabaseHas('patient_profiles', [
            'user_id' => $user->getKey(),
            'first_name' => 'Amine',
            'last_name' => 'Benali',
            'wilaya_code' => 16,
            'baladiya_id' => $baladiya->getKey(),
        ]);

        // The issued token authenticates the patient area…
        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/my/profile')
            ->assertOk();

        // …and is a 90-day token restricted to the mobile ability.
        $accessToken = $user->tokens()->firstOrFail();
        $this->assertSame('Pixel 8', $accessToken->name);
        $this->assertTrue($accessToken->can('mobile'));
        $this->assertFalse($accessToken->can('sync'));
        $this->assertNotNull($accessToken->expires_at);
        $this->assertTrue($accessToken->expires_at->greaterThan(now()->addDays(89)));
    }

    public function test_every_role_ish_field_is_rejected_with_422(): void
    {
        $prohibited = [
            'role' => 'Doctor',
            'roles' => ['Doctor'],
            'role_id' => 1,
            'is_platform_admin' => true,
            'cabinet_id' => 1,
            'approved_at' => '2026-01-01 00:00:00',
        ];

        $i = 0;
        foreach ($prohibited as $field => $value) {
            $i++;

            // One IP + one phone per attempt so the mobile-register limiter
            // never turns an expected 422 into a 429.
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])
                ->postJson('/api/v1/auth/register', $this->payload([
                    'phone' => sprintf('05512346%02d', $i),
                    $field => $value,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('patient_profiles', 0);
        $this->assertSame(0, User::query()->count());
    }

    public function test_malformed_phone_numbers_are_rejected(): void
    {
        foreach (['0212345678', '055123456', '05512345678', '+213551234567'] as $i => $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.($i + 1)])
                ->postJson('/api/v1/auth/register', $this->payload(['phone' => $phone]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone');
        }
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '0551234567']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['terms_accepted' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');

        $payload = $this->payload();
        unset($payload['terms_accepted']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_baladiya_must_belong_to_the_chosen_wilaya(): void
    {
        Wilaya::factory()->create(['code' => 16]);
        $other = Wilaya::factory()->create(['code' => 31]);
        $foreign = Baladiya::factory()->create(['wilaya_code' => $other->code]);

        $this->postJson('/api/v1/auth/register', $this->payload([
            'baladiya_id' => $foreign->getKey(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('baladiya_id');
    }
}
