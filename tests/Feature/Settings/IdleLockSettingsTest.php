<?php

namespace Tests\Feature\Settings;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\RoleName;
use App\Models\User;
use App\Services\ApplicationSettingService;
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

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('security.idle-lock.update'), [
                'idle_lock_minutes' => 61,
            ])
            ->assertSessionHasErrors('idle_lock_minutes');

        $this->assertSame(
            15,
            app(ApplicationSettingService::class)->get(
                ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES,
            ),
        );
    }
}
