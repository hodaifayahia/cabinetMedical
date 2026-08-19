<?php

namespace Tests\Feature\Auth;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\DesktopPinCredential;
use App\Models\License;
use App\Models\User;
use App\Services\Auth\DesktopPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DesktopPinAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_TOKEN = 'device_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const OTHER_DEVICE_TOKEN = 'device_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    public function test_an_active_cabinet_owner_can_enroll_a_device_without_persisting_plaintext_secrets(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $credential = DesktopPinCredential::withoutCabinetScope()->sole();

        $this->assertSame($owner->getKey(), $credential->user_id);
        $this->assertSame($cabinet->getKey(), $credential->cabinet_id);
        $this->assertSame('Poste accueil', $credential->device_name);
        $this->assertSame(
            hash_hmac('sha256', self::DEVICE_TOKEN, (string) config('app.key')),
            $credential->device_token_hash,
        );
        $this->assertNotSame(self::DEVICE_TOKEN, $credential->device_token_hash);
        $this->assertNotSame('2468', $credential->pin_hash);
        $this->assertTrue(Hash::check('2468', $credential->pin_hash));
        $this->assertArrayNotHasKey('device_token_hash', $credential->toArray());
        $this->assertArrayNotHasKey('pin_hash', $credential->toArray());

        $databaseRow = (array) DB::table('desktop_pin_credentials')->sole();
        $this->assertNotContains(self::DEVICE_TOKEN, $databaseRow, true);
        $this->assertNotContains('2468', $databaseRow, true);

        $audit = AuditLog::withoutCabinetScope()
            ->where('action', 'security.desktop_pin_enrolled')
            ->sole();
        $serializedAudit = $audit->toJson();
        $this->assertStringNotContainsString(self::DEVICE_TOKEN, $serializedAudit);
        $this->assertStringNotContainsString('2468', $serializedAudit);
        $this->assertStringNotContainsString($credential->device_token_hash, $serializedAudit);
        $this->assertStringNotContainsString($credential->pin_hash, $serializedAudit);
    }

    public function test_an_approved_staff_member_can_enroll_log_out_and_log_back_in_on_that_device(): void
    {
        [$cabinet] = $this->cabinetWithOwner();
        $staff = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($staff)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();

        $this->from(route('login'))
            ->post(route('desktop.pin.login'), $this->loginPayload())
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($staff);
        $credential = DesktopPinCredential::withoutCabinetScope()->sole();
        $this->assertNotNull($credential->last_used_at);
        $this->assertSame(0, $credential->failed_attempts);
        $this->assertNull($credential->locked_until);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.desktop_pin_login_succeeded',
            'user_id' => $staff->getKey(),
        ]);

        $serializedAudits = AuditLog::withoutCabinetScope()->get()->toJson();
        $this->assertStringNotContainsString(self::DEVICE_TOKEN, $serializedAudits);
        $this->assertStringNotContainsString('2468', $serializedAudits);
        $this->assertStringNotContainsString($credential->device_token_hash, $serializedAudits);
        $this->assertStringNotContainsString($credential->pin_hash, $serializedAudits);
    }

    public function test_reenrolling_the_same_device_rotates_the_pin_without_creating_a_second_record(): void
    {
        [, $owner] = $this->cabinetWithOwner();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303);

        $this->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), [
                ...$this->enrollmentPayload('1357'),
                'device_name' => 'Portable médecin',
            ])->assertStatus(303);

        $this->assertDatabaseCount('desktop_pin_credentials', 1);
        $credential = DesktopPinCredential::withoutCabinetScope()->sole();
        $this->assertSame('Portable médecin', $credential->device_name);
        $this->assertTrue(Hash::check('1357', $credential->pin_hash));
        $this->assertFalse(Hash::check('2468', $credential->pin_hash));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.desktop_pin_changed',
            'user_id' => $owner->getKey(),
        ]);
    }

    public function test_a_device_token_cannot_be_claimed_by_another_user(): void
    {
        [, $firstOwner] = $this->cabinetWithOwner('first@example.test');
        [, $secondOwner] = $this->cabinetWithOwner('second@example.test');

        $this->actingAs($firstOwner)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303);

        $this->actingAs($secondOwner)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload('1357'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors([
                'device_token' => DesktopPinService::ENROLLMENT_CONFLICT_MESSAGE,
            ]);

        $credential = DesktopPinCredential::withoutCabinetScope()->sole();
        $this->assertSame($firstOwner->getKey(), $credential->user_id);
        $this->assertTrue(Hash::check('2468', $credential->pin_hash));
    }

    public function test_the_pin_is_bound_to_the_exact_case_sensitive_device_token(): void
    {
        [, $owner] = $this->cabinetWithOwner();
        $this->enrollAndLogout($owner);

        foreach ([self::OTHER_DEVICE_TOKEN, strtoupper(self::DEVICE_TOKEN)] as $deviceToken) {
            $this->from(route('login'))
                ->post(route('desktop.pin.login'), $this->loginPayload(deviceToken: $deviceToken))
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors([
                    'pin' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
                ]);

            $this->assertGuest();
        }
    }

    public function test_five_wrong_pins_persistently_lock_the_device_and_the_generic_error_never_changes(): void
    {
        [, $owner] = $this->cabinetWithOwner();
        $this->enrollAndLogout($owner);

        for ($attempt = 1; $attempt <= DesktopPinService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->from(route('login'))
                ->post(route('desktop.pin.login'), $this->loginPayload(pin: '0000'))
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors([
                    'pin' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
                ]);
        }

        $credential = DesktopPinCredential::withoutCabinetScope()->sole();
        $this->assertSame(DesktopPinService::MAX_FAILED_ATTEMPTS, $credential->failed_attempts);
        $this->assertTrue($credential->locked_until?->isFuture() === true);

        $this->from(route('login'))
            ->post(route('desktop.pin.login'), $this->loginPayload())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'pin' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            ]);
        $this->assertGuest();

        $this->travel(DesktopPinService::LOCKOUT_MINUTES + 1)->minutes();

        $this->from(route('login'))
            ->post(route('desktop.pin.login'), $this->loginPayload())
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($owner);

        $credential->refresh();
        $this->assertSame(0, $credential->failed_attempts);
        $this->assertNull($credential->locked_until);
        $this->assertNotNull($credential->last_used_at);
    }

    public function test_unknown_device_attempts_are_throttled_without_echoing_the_token(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->from(route('login'))
                ->post(route('desktop.pin.login'), $this->loginPayload(deviceToken: self::OTHER_DEVICE_TOKEN))
                ->assertSessionHasErrors('pin');
        }

        $this->withHeader('Accept', 'application/json')
            ->postJson(route('desktop.pin.login'), $this->loginPayload(deviceToken: self::OTHER_DEVICE_TOKEN))
            ->assertTooManyRequests()
            ->assertDontSee(self::OTHER_DEVICE_TOKEN);
    }

    public function test_a_pending_owner_can_enroll_then_pin_login_is_still_gated_to_activation(): void
    {
        [$cabinet, $owner] = $this->cabinetWithOwner(status: CabinetStatus::PENDING);

        $this->actingAs($owner)
            ->from(route('cabinet.pending'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303)
            ->assertRedirect(route('cabinet.pending'));

        $this->post(route('logout'));
        $this->post(route('desktop.pin.login'), $this->loginPayload())
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($owner);

        $this->get(route('dashboard'))
            ->assertRedirect(route('cabinet.pending'));
        $this->assertSame(CabinetStatus::PENDING, $cabinet->fresh()->status);
    }

    public function test_a_pending_owner_is_told_desktop_pin_enrollment_is_available(): void
    {
        [, $owner] = $this->cabinetWithOwner(status: CabinetStatus::PENDING);

        $this->actingAs($owner)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.can.enrollDesktopPin', true),
            );
    }

    public function test_a_pending_non_owner_cannot_use_the_enrollment_exemption(): void
    {
        [$cabinet] = $this->cabinetWithOwner(status: CabinetStatus::PENDING);
        $staff = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($staff)
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
    }

    public function test_a_pending_non_owner_is_not_told_desktop_pin_enrollment_is_available(): void
    {
        [$cabinet] = $this->cabinetWithOwner(status: CabinetStatus::PENDING);
        $staff = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.can.enrollDesktopPin', false),
            );
    }

    public function test_suspended_credentials_are_revoked_and_inactive_cabinets_remain_middleware_gated(): void
    {
        foreach (['suspended', 'inactive_license'] as $state) {
            [$cabinet, $owner] = $this->cabinetWithOwner("{$state}@example.test");
            $deviceToken = $state === 'suspended'
                ? self::DEVICE_TOKEN
                : self::OTHER_DEVICE_TOKEN;
            $this->enrollAndLogout($owner, $deviceToken);

            if ($state === 'suspended') {
                $cabinet->forceFill(['status' => CabinetStatus::SUSPENDED])->save();
                $this->assertDatabaseMissing('desktop_pin_credentials', [
                    'cabinet_id' => $cabinet->getKey(),
                ]);
                $this->post(route('desktop.pin.login'), $this->loginPayload(deviceToken: $deviceToken))
                    ->assertSessionHasErrors([
                        'pin' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
                    ]);
                $this->assertGuest();
                $this->assertDatabaseHas('audit_logs', [
                    'action' => 'security.desktop_pin_credentials_revoked',
                    'subject_id' => (string) $cabinet->getKey(),
                ]);

                continue;
            } else {
                $license = License::query()->create([
                    'license_id' => 'PIN-INACTIVE-'.strtoupper(substr(hash('sha256', $state), 0, 12)),
                    'product' => 'drclickdz',
                    'edition' => 'hosted',
                    'plan' => LicensePlan::LIFETIME,
                    'customer_id' => (string) $cabinet->getKey(),
                    'status' => 'inactive',
                    'issued_at' => now(),
                ]);
                $cabinet->forceFill(['license_id' => $license->getKey()])->save();
            }

            $this->post(route('desktop.pin.login'), $this->loginPayload(deviceToken: $deviceToken))
                ->assertRedirect(route('dashboard', absolute: false));
            $this->assertAuthenticatedAs($owner);
            $this->get(route('dashboard'))
                ->assertRedirect(route('cabinet.pending'));

            $this->post(route('logout'));
        }
    }

    public function test_changing_the_account_password_revokes_every_desktop_pin_credential(): void
    {
        [, $owner] = $this->cabinetWithOwner();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303);

        $this->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'New-Strong-Password!2026',
            'password_confirmation' => 'New-Strong-Password!2026',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.desktop_pin_credentials_revoked',
            'user_id' => $owner->getKey(),
        ]);

        $this->post(route('logout'));
        $this->from(route('login'))
            ->post(route('desktop.pin.login'), $this->loginPayload())
            ->assertSessionHasErrors([
                'pin' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            ]);
        $this->assertGuest();
    }

    public function test_deleting_an_account_cascades_its_device_credentials(): void
    {
        [, $owner] = $this->cabinetWithOwner();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
            ->assertStatus(303);

        $owner->delete();

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
    }

    public function test_only_four_ascii_digits_are_accepted_and_sensitive_old_input_is_not_flashed(): void
    {
        [, $owner] = $this->cabinetWithOwner();

        foreach (['123', '12345', '１２３４', '12a4'] as $invalidPin) {
            $this->actingAs($owner)
                ->from(route('dashboard'))
                ->post(route('desktop.pin.enroll'), $this->enrollmentPayload($invalidPin))
                ->assertSessionHasErrors('pin')
                ->assertSessionMissing('_old_input.device_token')
                ->assertSessionMissing('_old_input.pin')
                ->assertSessionMissing('_old_input.pin_confirmation');
        }

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
    }

    public function test_platform_and_unscoped_accounts_cannot_enroll_desktop_pin_credentials(): void
    {
        $accounts = [
            User::factory()->create([
                'is_platform_admin' => true,
                'cabinet_id' => null,
                'approved_at' => now(),
            ]),
            User::factory()->create([
                'is_platform_admin' => false,
                'cabinet_id' => null,
                'approved_at' => now(),
            ]),
        ];

        foreach ($accounts as $account) {
            $this->actingAs($account)
                ->post(route('desktop.pin.enroll'), $this->enrollmentPayload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
    }

    /** @return array{Cabinet, User} */
    private function cabinetWithOwner(
        string $email = 'owner@example.test',
        CabinetStatus $status = CabinetStatus::ACTIVE,
    ): array {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => $status,
            'activated_at' => $status === CabinetStatus::ACTIVE ? now() : null,
        ]);
        $owner = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [$cabinet, $owner];
    }

    /** @return array{device_token: string, pin: string, pin_confirmation: string, device_name: string} */
    private function enrollmentPayload(
        string $pin = '2468',
        string $deviceToken = self::DEVICE_TOKEN,
    ): array {
        return [
            'device_token' => $deviceToken,
            'pin' => $pin,
            'pin_confirmation' => $pin,
            'device_name' => 'Poste accueil',
        ];
    }

    /** @return array{device_token: string, pin: string} */
    private function loginPayload(
        string $pin = '2468',
        string $deviceToken = self::DEVICE_TOKEN,
    ): array {
        return [
            'device_token' => $deviceToken,
            'pin' => $pin,
        ];
    }

    private function enrollAndLogout(
        User $user,
        string $deviceToken = self::DEVICE_TOKEN,
    ): void {
        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('desktop.pin.enroll'), $this->enrollmentPayload(deviceToken: $deviceToken))
            ->assertStatus(303);
        $this->post(route('logout'));
        $this->assertGuest();
    }
}
