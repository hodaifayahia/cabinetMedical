<?php

namespace Tests\Feature\Api\Mobile\Auth;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_patient_can_login_with_phone(): void
    {
        $patient = $this->makePatientUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->phone,
            'password' => 'password',
            'device_name' => 'Galaxy S24',
        ]);

        $response->assertOk()
            ->assertJsonPath('role', 'patient')
            ->assertJsonPath('user.id', $patient->getKey())
            ->assertJsonPath('user.phone', $patient->phone)
            ->assertJsonPath('user.role', 'patient');

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($response->json('user.first_name'));
    }

    public function test_patient_can_login_with_email(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('role', 'patient')
            ->assertJsonPath('user.id', $patient->getKey());
    }

    public function test_staff_login_returns_their_mobile_role_and_staff_payload(): void
    {
        ['doctorUser' => $doctorUser, 'cabinet' => $cabinet] = $this->makeListedClinic();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $doctorUser->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('role', 'doctor')
            ->assertJsonPath('user.id', $doctorUser->getKey())
            ->assertJsonPath('user.cabinet.id', $cabinet->getKey())
            ->assertJsonStructure(['token', 'user' => ['roles', 'permissions', 'cabinet']]);
    }

    public function test_assistant_login_maps_to_the_reception_role(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();

        $assistant = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $assistant->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('role', 'reception');
    }

    public function test_staff_of_a_pending_cabinet_is_denied_like_the_desktop_api(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet En Attente',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 16,
        ]);

        $doctor = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $doctor->assignRole(RoleName::DOCTOR->value);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $doctor->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('reason', 'cabinet_pending')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_patient_login_skips_cabinet_checks_entirely(): void
    {
        // A patient has no cabinet: no gate applies even when other cabinets
        // on the platform are pending or suspended.
        Cabinet::query()->create([
            'name' => 'Cabinet Suspendu',
            'status' => CabinetStatus::SUSPENDED,
            'wilaya_code' => 16,
        ]);
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->phone,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_wrong_password_is_rejected_with_422_on_identifier(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->phone,
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_identifier_is_rejected_with_422(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => '0599999999',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('identifier');
    }

    public function test_issued_token_expires_in_90_days_and_carries_only_the_mobile_ability(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->phone,
            'password' => 'password',
        ])->assertOk();

        $token = $patient->tokens()->firstOrFail();
        $this->assertSame('mobile', $token->name);
        $this->assertTrue($token->can('mobile'));
        $this->assertFalse($token->can('*'));
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(89)));
        $this->assertTrue($token->expires_at->lessThan(now()->addDays(91)));
    }
}
