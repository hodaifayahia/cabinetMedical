<?php

namespace Tests\Feature\Auth;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DesktopPinCredential;
use App\Models\User;
use App\Services\Auth\AccountRecoveryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Nouveau-Mot-De-Passe!2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_recovery_page_is_public_and_hides_the_device_key_on_the_web(): void
    {
        $this->get(route('account-recovery.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/AccountRecovery')
                ->where('deviceRecoveryAvailable', false));
    }

    public function test_recovery_codes_are_shown_once_and_stored_only_as_hashes(): void
    {
        [, $owner] = $this->cabinetWithUser();

        $codes = app(AccountRecoveryService::class)->generateCodes($owner);

        $this->assertCount(AccountRecoveryService::CODE_COUNT, $codes);
        $this->assertCount(AccountRecoveryService::CODE_COUNT, array_unique($codes));
        $this->assertMatchesRegularExpression('/\A[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}\z/', $codes[0]);

        $stored = (string) $owner->fresh()->account_recovery_codes;

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $stored);
        }

        $this->assertArrayNotHasKey('account_recovery_codes', $owner->fresh()->toArray());
    }

    public function test_the_security_page_creates_codes_and_reports_how_many_remain(): void
    {
        [, $owner] = $this->cabinetWithUser();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect(route('security.edit'));

        $this->assertSame(
            AccountRecoveryService::CODE_COUNT,
            app(AccountRecoveryService::class)->remainingCodes($owner->fresh()),
        );

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recoveryCodes.remaining', AccountRecoveryService::CODE_COUNT));
    }

    public function test_a_recovery_code_resets_the_password_once_and_revokes_every_pin(): void
    {
        [$cabinet, $owner] = $this->cabinetWithUser();
        $owner->forceFill(['local_pin_hash' => Hash::make('123456')])->save();
        DesktopPinCredential::withoutCabinetScope()->create([
            'user_id' => $owner->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'device_token_hash' => str_repeat('a', 64),
            'device_name' => 'Poste accueil',
            'pin_hash' => Hash::make('2468'),
        ]);
        $codes = app(AccountRecoveryService::class)->generateCodes($owner);

        $this->post(route('account-recovery.code'), $this->payload($owner->email, strtolower($codes[3])))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $owner->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->password));
        $this->assertNull($owner->local_pin_hash);
        $this->assertDatabaseCount('desktop_pin_credentials', 0);
        $this->assertSame(AccountRecoveryService::CODE_COUNT - 1, app(AccountRecoveryService::class)->remainingCodes($owner));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.password_recovered',
            'user_id' => $owner->getKey(),
        ]);
        $this->assertGuest();

        // The same code never works twice.
        $this->post(route('account-recovery.code'), $this->payload($owner->email, $codes[3], 'Encore-Un-Autre!2026'))
            ->assertSessionHasErrors(['code' => AccountRecoveryService::INVALID_MESSAGE]);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->fresh()->password));
    }

    public function test_a_wrong_code_or_another_accounts_code_is_refused_with_one_message(): void
    {
        [$cabinet, $owner] = $this->cabinetWithUser();
        $assistant = $this->createUser($cabinet, RoleName::RECEPTIONIST, ['email' => 'assistante@example.test']);
        $ownerCodes = app(AccountRecoveryService::class)->generateCodes($owner);
        app(AccountRecoveryService::class)->generateCodes($assistant);

        foreach ([
            [$owner->email, 'AAAA-BBBB-CCCC'],
            [$assistant->email, $ownerCodes[0]],
            ['inconnu@example.test', $ownerCodes[0]],
        ] as [$email, $code]) {
            $this->post(route('account-recovery.code'), $this->payload($email, $code))
                ->assertSessionHasErrors(['code' => AccountRecoveryService::INVALID_MESSAGE]);
        }

        $this->assertTrue(Hash::check('password', $assistant->fresh()->password));
        $this->assertSame(AccountRecoveryService::CODE_COUNT, app(AccountRecoveryService::class)->remainingCodes($owner->fresh()));
    }

    public function test_the_device_key_is_refused_outside_the_desktop_poste_principal(): void
    {
        $this->postJson(route('account-recovery.device.key'))->assertNotFound();

        config(['medismart.runtime.desktop_supervised' => true]);

        // Another computer of the network is not the poste principal.
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->postJson(route('account-recovery.device.key'))
            ->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->postJson(route('account-recovery.device.key'))
            ->assertNotFound();
    }

    public function test_the_poste_principal_key_file_resets_a_password_once(): void
    {
        $this->actAsDesktopPostePrincipal();
        [, $owner] = $this->cabinetWithUser();
        $service = app(AccountRecoveryService::class);

        $this->get('/account-recovery')
            ->assertInertia(fn (Assert $page) => $page->where('deviceRecoveryAvailable', true));

        $path = $this->postJson('/account-recovery/device/key')
            ->assertOk()
            ->json('path');

        $this->assertSame($service->deviceCodePath(), $path);
        preg_match('/Code : ([A-Z2-9]{4}-[A-Z2-9]{4})/', File::get($path), $match);
        $this->assertNotEmpty($match[1] ?? null);

        $this->post('/account-recovery/device', $this->payload($owner->email, $match[1]))
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->fresh()->password));
        $this->assertFileDoesNotExist($path);

        $this->post('/account-recovery/device', $this->payload($owner->email, $match[1], 'Encore-Un-Autre!2026'))
            ->assertSessionHasErrors('code');
    }

    public function test_five_wrong_poste_principal_codes_burn_the_key(): void
    {
        $this->actAsDesktopPostePrincipal();
        [, $owner] = $this->cabinetWithUser();

        $path = $this->postJson('/account-recovery/device/key')->json('path');
        preg_match('/Code : ([A-Z2-9]{4}-[A-Z2-9]{4})/', File::get($path), $match);

        $service = app(AccountRecoveryService::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $service->resetWithDeviceCode($owner->email, 'ZZZZ-ZZZZ', self::NEW_PASSWORD);
                $this->fail('A wrong code must be refused.');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->expectException(ValidationException::class);

        try {
            $service->resetWithDeviceCode($owner->email, $match[1], self::NEW_PASSWORD);
        } finally {
            $this->assertTrue(Hash::check('password', $owner->fresh()->password));
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_a_manager_resets_a_colleagues_pins_but_not_in_another_cabinet(): void
    {
        [$cabinet, $owner] = $this->cabinetWithUser();
        $assistant = $this->createUser($cabinet, RoleName::RECEPTIONIST, [
            'email' => 'assistante@example.test',
            'local_pin_hash' => Hash::make('123456'),
        ]);
        DesktopPinCredential::withoutCabinetScope()->create([
            'user_id' => $assistant->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'device_token_hash' => str_repeat('b', 64),
            'device_name' => 'Poste accueil',
            'pin_hash' => Hash::make('2468'),
        ]);
        [, $otherOwner] = $this->cabinetWithUser('autre@example.test');

        $this->actingAs($otherOwner)
            ->delete(route('app.staff.pins.reset', $assistant))
            ->assertStatus(403);
        $this->assertDatabaseCount('desktop_pin_credentials', 1);

        $this->actingAs($owner)
            ->delete(route('app.staff.pins.reset', $assistant))
            ->assertRedirect(route('app.staff.index'));

        $this->assertDatabaseCount('desktop_pin_credentials', 0);
        $this->assertNull($assistant->fresh()->local_pin_hash);
        $this->assertTrue(Hash::check('password', $assistant->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.pins_reset_by_manager',
            'user_id' => $owner->getKey(),
        ]);
    }

    private function actAsDesktopPostePrincipal(): void
    {
        URL::forceRootUrl('http://127.0.0.1:43123');
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.remote_upload_url' => null,
        ]);
        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ]);
    }

    /** @return array{Cabinet, User} */
    private function cabinetWithUser(string $email = 'docteur@example.test'): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = $this->createUser($cabinet, RoleName::DOCTOR, ['email' => $email]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [$cabinet, $owner];
    }

    /** @param array<string, mixed> $attributes */
    private function createUser(Cabinet $cabinet, RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($cabinet)->getKey(),
            'approved_at' => now(),
            ...$attributes,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /** @return array<string, string> */
    private function payload(string $email, string $code, string $password = self::NEW_PASSWORD): array
    {
        return [
            'email' => $email,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
