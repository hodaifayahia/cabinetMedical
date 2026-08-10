<?php

namespace Tests\Feature\Auth;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\DesktopPinCredential;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
                ->has('specialtySuggestions')
                ->has('wilayas', 58));
    }

    public function test_registration_screen_is_available_even_after_accounts_exist(): void
    {
        User::factory()->create();

        $this->get(route('register'))->assertOk();
    }

    public function test_registering_creates_a_pending_cabinet_with_an_owner(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test Owner',
            'cabinet_name' => 'Cabinet Test',
            'specialization' => 'Pédiatrie',
            'phone' => '+213 555 12 34 56',
            'email' => 'owner@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // The owner is authenticated but held out of the app until activation.
        $this->assertAuthenticated();
        $response->assertRedirect();

        $owner = User::query()->where('email', 'owner@example.com')->sole();
        $this->assertNotNull($owner->cabinet_id);
        $this->assertNotNull($owner->approved_at);
        $this->assertTrue($owner->hasRole(RoleName::ADMINISTRATOR->value));

        $cabinet = Cabinet::query()->sole();
        $this->assertSame('Cabinet Test', $cabinet->name);
        $this->assertSame(CabinetStatus::PENDING, $cabinet->status);
        $this->assertSame(16, $cabinet->wilaya_code);
        $this->assertSame($owner->getKey(), $cabinet->owner_user_id);

        $profile = $owner->doctorProfile;
        $this->assertNotNull($profile);
        $this->assertSame('Pédiatrie', $profile->specialty);
        $this->assertSame('+213 555 12 34 56', $profile->phone);
        $this->assertSame('owner@example.com', $profile->email);
        $this->assertSame(5, $profile->schedules()->count());
        $this->assertSame('+213 555 12 34 56', $cabinet->settings?->phone);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cabinet.registered',
        ]);
    }

    public function test_a_pending_cabinet_owner_is_redirected_to_the_pending_page(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Pending Owner',
            'cabinet_name' => 'Cabinet Pending',
            'specialization' => 'Cardiologie',
            'phone' => '0555 00 00 01',
            'email' => 'pending@example.com',
            'wilaya' => 31,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('cabinet.pending'));
    }

    public function test_desktop_registration_can_configure_the_device_pin_in_the_same_flow(): void
    {
        $deviceToken = str_repeat('a', 64);

        $this->post(route('register.store'), [
            'name' => 'Desktop Owner',
            'cabinet_name' => 'Cabinet Desktop',
            'specialization' => 'Cardiologie',
            'phone' => '0555 00 00 04',
            'email' => 'desktop-owner@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_token' => $deviceToken,
            'device_name' => 'Poste Drclick · Windows',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ])->assertRedirect();

        $owner = User::query()->where('email', 'desktop-owner@example.com')->sole();
        $credential = DesktopPinCredential::query()->sole();

        $this->assertSame($owner->getKey(), $credential->user_id);
        $this->assertSame($owner->cabinet_id, $credential->cabinet_id);
        $this->assertSame('Poste Drclick · Windows', $credential->device_name);
        $this->assertTrue(Hash::check('2468', $credential->pin_hash));
        $this->assertNotSame($deviceToken, $credential->device_token_hash);
    }

    public function test_invalid_desktop_pin_does_not_create_a_partial_cabinet(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Invalid Pin Owner',
            'cabinet_name' => 'Cabinet Invalid Pin',
            'specialization' => 'Cardiologie',
            'phone' => '0555 00 00 05',
            'email' => 'invalid-pin@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_token' => str_repeat('b', 64),
            'device_name' => 'Poste Drclick',
            'pin' => '123',
            'pin_confirmation' => '123',
        ])->assertSessionHasErrors('pin');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseCount('desktop_pin_credentials', 0);
    }

    public function test_registration_requires_a_valid_wilaya(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Bad Wilaya',
            'cabinet_name' => 'Cabinet X',
            'specialization' => 'Cardiologie',
            'phone' => '0555 00 00 02',
            'email' => 'bad@example.com',
            'wilaya' => 99,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('wilaya');

        $this->assertGuest();
        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_registration_requires_a_cabinet_name_and_specialization(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Owner',
            'phone' => '0555 00 00 03',
            'email' => 'owner2@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['cabinet_name', 'specialization']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_a_valid_phone_number(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Owner',
            'cabinet_name' => 'Cabinet Contact',
            'specialization' => 'Cardiologie',
            'phone' => 'not-a-phone',
            'email' => 'contact@example.com',
            'wilaya' => 16,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
        $this->assertDatabaseCount('cabinets', 0);
    }
}
