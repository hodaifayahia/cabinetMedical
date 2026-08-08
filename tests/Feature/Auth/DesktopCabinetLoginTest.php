<?php

namespace Tests\Feature\Auth;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class DesktopCabinetLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_cabinet_login_screen_can_be_rendered(): void
    {
        $this->get(route('desktop.cabinet-login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/DesktopCabinetLogin'));
    }

    public function test_staff_can_login_with_their_cabinet_owners_email_and_precreated_credentials(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner('owner@example.com');
        $staff = User::factory()->create([
            'email' => 'Staff.Mixed@Example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->post(route('desktop.cabinet-login.store'), [
            'owner_email' => ' OWNER@example.com ',
            'email' => ' STAFF.MIXED@example.COM ',
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($staff);
        $this->assertNotSame($owner->getKey(), auth()->id());
    }

    public function test_staff_credentials_are_rejected_for_a_different_cabinet_owner(): void
    {
        [$cabinet] = $this->cabinetWithOwner('owner@example.com');
        [, $differentOwner] = $this->cabinetWithOwner('different-owner@example.com');
        User::factory()->create([
            'email' => 'staff@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->from(route('desktop.cabinet-login'))
            ->post(route('desktop.cabinet-login.store'), [
                'owner_email' => $differentOwner->email,
                'email' => 'staff@example.com',
                'password' => 'password',
            ])
            ->assertRedirect(route('desktop.cabinet-login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_invalid_staff_password_is_rejected_without_revealing_the_cabinet(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner('owner@example.com');
        User::factory()->create([
            'email' => 'staff@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->post(route('desktop.cabinet-login.store'), [
            'owner_email' => $owner->email,
            'email' => 'staff@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_unapproved_unscoped_and_platform_accounts_cannot_use_cabinet_staff_login(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner('owner@example.com');
        $platformAdmin = User::factory()->create([
            'email' => 'platform@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'is_platform_admin' => true,
        ]);
        $unapprovedStaff = User::factory()->create([
            'email' => 'pending@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        foreach ([$platformAdmin, $unapprovedStaff] as $account) {
            $this->post(route('desktop.cabinet-login.store'), [
                'owner_email' => $owner->email,
                'email' => $account->email,
                'password' => 'password',
            ])->assertSessionHasErrors('email');
        }

        $this->assertGuest();
    }

    public function test_staff_two_factor_authentication_is_not_bypassed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        [$cabinet, $owner] = $this->cabinetWithOwner('owner@example.com');
        $staff = User::factory()->withTwoFactor()->create([
            'email' => 'staff@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->post(route('desktop.cabinet-login.store'), [
            'owner_email' => $owner->email,
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $staff->getKey());

        $this->assertGuest();
    }

    public function test_existing_cabinet_login_is_rate_limited(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner('owner@example.com');
        $staff = User::factory()->create([
            'email' => 'staff@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $payload = [
            'owner_email' => $owner->email,
            'email' => $staff->email,
            'password' => 'wrong-password',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $payload['email'] = str_repeat(' ', $attempt + 1).$staff->email.str_repeat(' ', $attempt + 1);

            $this->post(route('desktop.cabinet-login.store'), $payload)
                ->assertSessionHasErrors('email');
        }

        $payload['email'] = $staff->email;
        $this->post(route('desktop.cabinet-login.store'), $payload)
            ->assertTooManyRequests();
    }

    /**
     * @return array{Cabinet, User}
     */
    private function cabinetWithOwner(string $email): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [$cabinet, $owner];
    }
}
