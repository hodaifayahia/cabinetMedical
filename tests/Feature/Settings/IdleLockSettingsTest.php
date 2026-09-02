<?php

namespace Tests\Feature\Settings;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\RoleName;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\SessionLockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleLockSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_administrator_can_update_the_idle_lock_setting(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('security.idle-lock.update'), [
                'idle_lock_minutes' => 7,
            ])
            ->assertRedirect(route('security.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            7,
            app(ApplicationSettingService::class)->get(
                ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES,
            ),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.idle_lock_updated',
            'user_id' => $administrator->getKey(),
        ]);
    }

    public function test_idle_lock_update_requires_permission_and_recent_password_confirmation(): void
    {
        $ordinaryUser = User::factory()->create();

        $this->actingAs($ordinaryUser)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('security.idle-lock.update'), [
                'idle_lock_minutes' => 8,
            ])
            ->assertForbidden();

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->put(route('security.idle-lock.update'), [
                'idle_lock_minutes' => 8,
            ])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_idle_lock_setting_respects_registry_bounds(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        // Read the ceiling rather than restating it: the policy is a config
        // value, and a test that hardcodes the number fails on the day it moves
        // without anything actually being broken.
        $maximum = (int) config('medismart.security.maximum_idle_lock_minutes');
        $default = (int) config('medismart.security.default_idle_lock_minutes');

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('security.idle-lock.update'), [
                'idle_lock_minutes' => $maximum + 1,
            ])
            ->assertSessionHasErrors('idle_lock_minutes');

        $this->assertSame(
            $default,
            app(ApplicationSettingService::class)->get(
                ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES,
            ),
        );
    }

    /**
     * A workstation left alone locks after three hours; a screen being worked
     * in never does, because every pointer move restarts the countdown.
     *
     * session.lifetime has to cover it: Laravel measures its own inactivity
     * window separately, so a shorter one would sign the doctor out before the
     * lock ever fired and make the setting look ignored.
     */
    public function test_the_default_idle_lock_is_three_hours_and_the_session_outlasts_it(): void
    {
        $this->assertSame(180, (int) config('medismart.security.default_idle_lock_minutes'));

        $this->assertGreaterThanOrEqual(
            (int) config('medismart.security.default_idle_lock_minutes'),
            (int) config('session.lifetime'),
            'the session expires before the idle lock, so the lock duration is unreachable',
        );

        $this->assertSame(
            180 * 60,
            app(SessionLockService::class)->idleTimeoutSeconds(),
        );
    }
}
