<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('canRegister', true)
                ->where('canResetPassword', false));
    }

    public function test_login_offers_cabinet_registration_and_only_real_mail_recovery(): void
    {
        User::factory()->create();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.medismart.test',
        ]);

        // Multi-cabinet: registration stays open so new cabinets can sign up.
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRegister', true)
                ->where('canResetPassword', true));
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_platform_administrators_are_sent_to_filament_after_login(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/admin');
    }

    public function test_inertia_platform_login_uses_a_full_location_visit_for_filament(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->withHeader('X-Inertia', 'true')
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticatedAs($user);
        $response->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/admin');
    }

    public function test_an_authenticated_platform_administrator_is_sent_from_login_to_filament(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect('/admin');
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_platform_administrator_two_factor_login_finishes_in_filament(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create([
            'is_platform_admin' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'recovery-code-1',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_desktop_users_are_returned_to_the_pin_login_after_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout', ['desktop' => 1]));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
