<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Register')
                ->has('specialtySuggestions', 21));
    }

    public function test_first_owner_can_register_once(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'specialty' => 'Pédiatrie',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $owner = User::query()->sole();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotNull($owner->cabinet_setting_id);
        $this->assertTrue($owner->hasRole(RoleName::SUPER_ADMINISTRATOR->value));
        $profile = $owner->doctorProfile;
        $this->assertNotNull($profile);
        $this->assertSame('Pédiatrie', $profile->specialty);
        $this->assertSame('pediatrics', $profile->specialty_code);
        $this->assertNotNull($profile->specialty_locked_at);
        $this->assertSame(5, $profile->schedules()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'installation.initial_owner_created',
            'user_id' => $owner->getKey(),
        ]);
    }

    public function test_registration_is_hidden_and_rejected_after_an_account_exists(): void
    {
        User::factory()->create();

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'Second Owner',
            'specialty' => 'Cardiologie',
            'email' => 'second@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
    }

    public function test_initial_owner_must_choose_a_specialty(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Owner Without Specialty',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('specialty');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('doctor_profiles', 0);
    }
}
