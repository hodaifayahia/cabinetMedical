<?php

namespace Tests\Feature\Console;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionPlatformSuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_strong_password_once_and_creates_an_isolated_platform_admin(): void
    {
        $exitCode = Artisan::call('platform:provision-superadmin', [
            '--email' => 'ADMIN@EXAMPLE.COM',
            '--name' => 'Platform Admin',
            '--generate-password' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertMatchesRegularExpression(
            '/Mot de passe initial \(affiché une seule fois\) : ([^\r\n]+)/u',
            $output,
        );
        preg_match('/Mot de passe initial \(affiché une seule fois\) : ([^\r\n]+)/u', $output, $matches);
        $password = trim($matches[1]);

        $this->assertSame(24, strlen($password));
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
        $this->assertSame(1, substr_count($output, $password));

        $user = User::query()->where('email', 'admin@example.com')->sole();
        $this->assertTrue($user->is_platform_admin);
        $this->assertNull($user->cabinet_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->approved_at);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertCount(0, $user->roles);
    }

    public function test_repeated_provisioning_updates_the_same_platform_account_without_roles(): void
    {
        Artisan::call('platform:provision-superadmin', [
            '--email' => 'admin@example.com',
            '--name' => 'Original Name',
            '--generate-password' => true,
            '--no-interaction' => true,
        ]);
        $original = User::query()->where('email', 'admin@example.com')->sole();
        $originalPassword = $original->password;

        $exitCode = Artisan::call('platform:provision-superadmin', [
            '--email' => 'ADMIN@example.com',
            '--name' => 'Updated Name',
            '--generate-password' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['admin@example.com'])->count());

        $updated = User::query()->where('email', 'admin@example.com')->sole();
        $this->assertSame($original->getKey(), $updated->getKey());
        $this->assertSame('Updated Name', $updated->name);
        $this->assertNotSame($originalPassword, $updated->password);
        $this->assertNull($updated->cabinet_id);
        $this->assertCount(0, $updated->roles);

        $output = Artisan::output();
        preg_match('/Mot de passe initial \(affiché une seule fois\) : ([^\r\n]+)/u', $output, $matches);
        $this->assertTrue(Hash::check(trim($matches[1]), $updated->password));
        $this->assertSame(1, substr_count($output, trim($matches[1])));
    }

    public function test_it_refuses_to_promote_or_mutate_an_existing_tenant_user(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet existant',
            'status' => CabinetStatus::ACTIVE,
        ]);
        $tenantUser = User::factory()->create([
            'name' => 'Tenant Doctor',
            'email' => 'doctor@example.com',
            'cabinet_id' => $cabinet->getKey(),
            'is_platform_admin' => false,
        ]);
        $passwordHash = $tenantUser->password;

        $exitCode = Artisan::call('platform:provision-superadmin', [
            '--email' => 'doctor@example.com',
            '--name' => 'Attempted Admin',
            '--generate-password' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString('Mot de passe initial', Artisan::output());

        $tenantUser->refresh();
        $this->assertFalse($tenantUser->is_platform_admin);
        $this->assertSame('Tenant Doctor', $tenantUser->name);
        $this->assertSame($passwordHash, $tenantUser->password);
        $this->assertSame($cabinet->getKey(), $tenantUser->cabinet_id);
    }

    public function test_interactive_mode_uses_hidden_password_confirmation(): void
    {
        $password = 'Very-Strong!Pass2026';

        $this->artisan('platform:provision-superadmin', [
            '--email' => 'interactive@example.com',
            '--name' => 'Interactive Admin',
        ])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'interactive@example.com')->sole();
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_non_interactive_mode_never_accepts_a_password_on_the_command_line(): void
    {
        $exitCode = Artisan::call('platform:provision-superadmin', [
            '--email' => 'missing-password@example.com',
            '--name' => 'Missing Password',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseMissing('users', ['email' => 'missing-password@example.com']);
        $this->assertStringContainsString('--generate-password', Artisan::output());
    }
}
