<?php

namespace Tests\Feature\Configuration;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Middleware\EnsureGoogleOAuthLoopback;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class BackupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const SENSITIVE_ROUTE_PERMISSIONS = [
        'app.configuration.backup.local' => 'configuration.backups.manage',
        'app.configuration.backup.local.encrypted' => 'configuration.backups.manage',
        'app.configuration.backup.restore' => 'configuration.restore.manage',
        'app.configuration.backup.restore.prepare' => 'configuration.restore.manage',
        'app.configuration.backup.google.prepare' => 'configuration.drive.manage',
        'app.configuration.backup.google.files' => 'configuration.drive.manage',
        'app.configuration.backup.google.files.download' => 'configuration.drive.manage',
        'app.configuration.backup.google.files.destroy' => 'configuration.drive.manage',
        'app.configuration.backup.google.disconnect' => 'configuration.drive.manage',
        'app.configuration.backup.google.test' => 'configuration.drive.manage',
        'app.configuration.backup.drive.store' => 'configuration.drive.manage',
        'app.configuration.backup.drive.cancel' => 'configuration.drive.manage',
        'app.configuration.updates.prepare-install' => 'configuration.connectivity.manage',
    ];

    /** @var list<string> */
    private const RECENT_CONFIRMATION_ROUTES = [
        'app.configuration.backup.local',
        'app.configuration.backup.local.encrypted',
        'app.configuration.backup.restore',
        'app.configuration.backup.restore.prepare',
        'app.configuration.backup.google.prepare',
        'app.configuration.backup.google.files.download',
        'app.configuration.backup.google.files.destroy',
        'app.configuration.backup.google.disconnect',
        'app.configuration.backup.drive.store',
        'app.configuration.updates.prepare-install',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_sensitive_route_requires_its_least_privilege_permission(): void
    {
        foreach (self::SENSITIVE_ROUTE_PERMISSIONS as $routeName => $permission) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route);
            $this->assertContains(
                'permission:'.$permission,
                $route->gatherMiddleware(),
                "The {$routeName} route must require {$permission}.",
            );
        }
    }

    public function test_a_receptionist_is_forbidden_from_every_backup_endpoint(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole(RoleName::RECEPTIONIST->value);
        $this->actingAs($receptionist);

        $this->get(route('app.configuration.backup.local'))->assertForbidden();
        $this->post(route('app.configuration.backup.local.encrypted'))->assertForbidden();
        $this->post(route('app.configuration.backup.restore'))->assertForbidden();
        $this->post(route('app.configuration.backup.restore.prepare'))->assertForbidden();
        $this->post(route('app.configuration.backup.google.prepare'))->assertForbidden();
        $this->get(route('app.configuration.backup.google.files'))->assertForbidden();
        $this->post(route('app.configuration.backup.google.files.download', ['fileId' => 'remote-id']))->assertForbidden();
        $this->delete(route('app.configuration.backup.google.files.destroy', ['fileId' => 'remote-id']))->assertForbidden();
        $this->delete(route('app.configuration.backup.google.disconnect'))->assertForbidden();
        $this->post(route('app.configuration.backup.google.test'))->assertForbidden();
        $this->post(route('app.configuration.backup.drive.store'))->assertForbidden();
        $this->delete(route('app.configuration.backup.drive.cancel', ['backupRecordId' => '00000000-0000-4000-8000-000000000000']))->assertForbidden();
        $this->post(route('app.configuration.updates.prepare-install'))->assertForbidden();
    }

    public function test_sensitive_backup_actions_require_recent_password_confirmation(): void
    {
        foreach (self::RECENT_CONFIRMATION_ROUTES as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route);
            $this->assertContains(
                'password.confirm',
                $route->gatherMiddleware(),
                "The {$routeName} route must require recent password confirmation.",
            );
        }

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->get(route('app.configuration.backup.local'))
            ->assertRedirect(route('password.confirm'));
        $this->assertDatabaseCount('backup_records', 0);
    }

    public function test_oauth_callback_is_unauthenticated_but_restricted_to_the_exact_loopback(): void
    {
        $route = app('router')->getRoutes()->getByName('app.configuration.backup.google.callback');

        $this->assertInstanceOf(Route::class, $route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains(EnsureGoogleOAuthLoopback::class, $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('verified', $middleware);
        $this->assertNotContains('permission:settings.manage', $middleware);
        $this->assertNotContains('password.confirm', $middleware);

        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'services.google.redirect' => null,
        ]);

        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
        ])->get('http://127.0.0.1:43123/app/configuration/backup/google/callback?state=invalid')
            ->assertStatus(400)
            ->assertSee('revenir &agrave; MediSmart', false);
    }

    public function test_an_administrator_passes_backup_authorization(): void
    {
        config(['medismart.backups.legacy_restore_enabled' => true]);

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('app.configuration.backup.restore'))
            ->assertRedirect()
            ->assertSessionHasErrors('backup');
    }

    public function test_restore_preparation_is_limited_to_administrator_roles_even_with_direct_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CONFIGURATION_RESTORE_MANAGE->value);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('app.configuration.backup.restore.prepare'))
            ->assertForbidden();
    }

    public function test_restore_preparation_route_keeps_all_authentication_boundaries(): void
    {
        $route = app('router')->getRoutes()->getByName('app.configuration.backup.restore.prepare');

        $this->assertInstanceOf(Route::class, $route);
        $middleware = $route->gatherMiddleware();

        foreach ([
            'auth',
            'verified',
            'permission:configuration.restore.manage',
            'password.confirm',
        ] as $required) {
            $this->assertContains($required, $middleware);
        }

        $this->postJson(route('app.configuration.backup.restore.prepare'))
            ->assertUnauthorized();

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->post(route('app.configuration.backup.restore.prepare'))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'restore.offline_prepared',
        ]);
    }

    public function test_restore_preparation_is_not_excluded_from_csrf_protection(): void
    {
        $this->get('/')->assertOk();

        $middleware = new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $request = Request::create('/app/configuration/backup/restore/prepare', 'POST');
        $request->setLaravelSession($this->app->make('session')->driver());

        $this->expectException(TokenMismatchException::class);

        $middleware->handle(
            $request,
            static fn () => response()->json(['ok' => true]),
        );
    }
}
