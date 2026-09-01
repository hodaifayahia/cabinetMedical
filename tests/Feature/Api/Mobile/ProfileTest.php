<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Baladiya;
use App\Models\User;
use App\Models\Wilaya;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_show_returns_the_profile_with_wilaya_and_baladiya_names(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => 31, 'name_fr' => 'Oran', 'name_ar' => 'وهران']);
        $baladiya = Baladiya::factory()->create(['wilaya_code' => $wilaya->code, 'name_fr' => 'Es Senia']);

        $patient = $this->makePatientUser();
        $patient->patientProfile->update([
            'wilaya_code' => $wilaya->code,
            'baladiya_id' => $baladiya->getKey(),
        ]);

        Sanctum::actingAs($patient);

        $this->getJson('/api/v1/my/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $patient->getKey())
            ->assertJsonPath('data.phone', $patient->phone)
            ->assertJsonPath('data.role', 'patient')
            ->assertJsonPath('data.first_name', $patient->patientProfile->first_name)
            ->assertJsonPath('data.wilaya.code', 31)
            ->assertJsonPath('data.wilaya.name_fr', 'Oran')
            ->assertJsonPath('data.wilaya.name_ar', 'وهران')
            ->assertJsonPath('data.baladiya.id', $baladiya->getKey())
            ->assertJsonPath('data.baladiya.name_fr', 'Es Senia')
            ->assertJsonStructure(['data' => [
                'id', 'phone', 'email', 'role', 'first_name', 'last_name',
                'gender', 'date_of_birth', 'place_of_birth', 'wilaya', 'baladiya',
            ]]);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/my/profile')->assertStatus(401);
    }

    public function test_staff_cannot_access_the_patient_profile_endpoints(): void
    {
        ['doctorUser' => $doctorUser] = $this->makeListedClinic();
        Sanctum::actingAs($doctorUser);

        $this->getJson('/api/v1/my/profile')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_role_required');

        $this->patchJson('/api/v1/my/profile', ['first_name' => 'X'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_role_required');
    }

    public function test_update_persists_partial_demographic_changes(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => 25, 'name_fr' => 'Constantine']);
        $baladiya = Baladiya::factory()->create(['wilaya_code' => $wilaya->code, 'name_fr' => 'El Khroub']);

        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        $this->patchJson('/api/v1/my/profile', [
            'first_name' => 'Yasmine',
            'place_of_birth' => 'Constantine',
            'wilaya_code' => 25,
            'baladiya_id' => $baladiya->getKey(),
            'email' => 'yasmine@example.com',
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Yasmine')
            ->assertJsonPath('data.place_of_birth', 'Constantine')
            ->assertJsonPath('data.email', 'yasmine@example.com')
            ->assertJsonPath('data.wilaya.code', 25)
            ->assertJsonPath('data.baladiya.id', $baladiya->getKey());

        $this->assertDatabaseHas('patient_profiles', [
            'user_id' => $patient->getKey(),
            'first_name' => 'Yasmine',
            'wilaya_code' => 25,
            'baladiya_id' => $baladiya->getKey(),
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $patient->getKey(),
            'email' => 'yasmine@example.com',
        ]);
    }

    public function test_update_validates_the_baladiya_against_the_stored_wilaya_when_none_is_sent(): void
    {
        $stored = Wilaya::factory()->create(['code' => 16]);
        $storedBaladiya = Baladiya::factory()->create(['wilaya_code' => $stored->code]);
        $other = Wilaya::factory()->create(['code' => 31]);
        $foreign = Baladiya::factory()->create(['wilaya_code' => $other->code]);

        $patient = $this->makePatientUser();
        $patient->patientProfile->update(['wilaya_code' => 16]);
        Sanctum::actingAs($patient);

        // A commune of another wilaya is refused…
        $this->patchJson('/api/v1/my/profile', ['baladiya_id' => $foreign->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('baladiya_id');

        // …while one of the stored wilaya passes.
        $this->patchJson('/api/v1/my/profile', ['baladiya_id' => $storedBaladiya->getKey()])
            ->assertOk()
            ->assertJsonPath('data.baladiya.id', $storedBaladiya->getKey());
    }

    public function test_update_rejects_invalid_values(): void
    {
        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        $this->patchJson('/api/v1/my/profile', ['gender' => 'autre'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gender');

        $this->patchJson('/api/v1/my/profile', ['date_of_birth' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_of_birth');

        $this->patchJson('/api/v1/my/profile', ['wilaya_code' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wilaya_code');
    }

    public function test_update_rejects_an_email_already_taken_by_another_account(): void
    {
        $other = User::factory()->create(['email' => 'pris@example.com']);
        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        $this->patchJson('/api/v1/my/profile', ['email' => 'pris@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Re-submitting one's own e-mail is not a conflict.
        $this->patchJson('/api/v1/my/profile', ['email' => $patient->email])
            ->assertOk();
    }

    public function test_phone_is_not_updatable(): void
    {
        $patient = $this->makePatientUser();
        $originalPhone = $patient->phone;
        Sanctum::actingAs($patient);

        $this->patchJson('/api/v1/my/profile', [
            'phone' => '0699999999',
            'first_name' => 'Nadia',
        ])->assertOk()
            ->assertJsonPath('data.phone', $originalPhone);

        $this->assertDatabaseHas('users', [
            'id' => $patient->getKey(),
            'phone' => $originalPhone,
        ]);
    }
}
