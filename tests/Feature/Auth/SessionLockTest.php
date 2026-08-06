<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_explicitly_lock_without_being_logged_out(): void
    {
        $user = User::factory()->create();
        $instanceId = str_repeat('A', 40);

        $this->actingAs($user)
            ->withSession([
                'auth.password_confirmed_at' => time(),
                '_old_input' => ['patient' => 'sensitive'],
                SessionLockService::SESSION_USER_ID => $user->getKey(),
                SessionLockService::SESSION_LAST_ACTIVITY_AT => now()->timestamp,
                SessionLockService::SESSION_INSTANCE_ID => $instanceId,
            ])
            ->post(route('session-lock.lock'), [
                'intended' => '/dashboard?from=lock',
                'session_instance_id' => $instanceId,
            ])
            ->assertRedirect(route('session-lock.show'))
            ->assertSessionHas(SessionLockService::SESSION_LOCKED_AT)
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionMissing('_old_input');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.session_locked',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_lock_screen_has_only_minimal_non_medical_props(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->actingAs($user)
            ->withSession($this->lockedSession($user))
            ->get(route('session-lock.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/LockScreen')
                ->where('pinConfigured', true)
                ->missing('auth')
                ->missing('cabinet')
                ->missing('name'));
    }

    public function test_pin_unlock_regenerates_the_session_and_does_not_confirm_sensitive_actions(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->actingAs($user)
            ->withSession([
                ...$this->lockedSession($user, '/dashboard?restored=1'),
                '_token' => 'known-csrf-token',
                'auth.password_confirmed_at' => time(),
            ])
            ->post(route('session-lock.unlock'), [
                'method' => 'pin',
                'pin' => '123456',
            ])
            ->assertRedirect('/dashboard?restored=1')
            ->assertSessionMissing(SessionLockService::SESSION_LOCKED_AT)
            ->assertSessionMissing('auth.password_confirmed_at');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('known-csrf-token', session()->token());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.session_unlocked',
            'user_id' => $user->getKey(),
        ]);

        $serializedAudit = AuditLog::query()
            ->where('action', 'security.session_unlocked')
            ->sole()
            ->toJson();
        $this->assertStringNotContainsString('123456', $serializedAudit);
        $this->assertStringNotContainsString($user->local_pin_hash, $serializedAudit);
    }

    public function test_current_password_is_always_available_as_unlock_fallback(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
            'local_pin_hash' => null,
        ]);

        $this->actingAs($user)
            ->withSession($this->lockedSession($user))
            ->post(route('session-lock.unlock'), [
                'method' => 'password',
                'password' => 'current-password',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(SessionLockService::SESSION_LOCKED_AT)
            ->assertSessionMissing('auth.password_confirmed_at');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_unlock_is_generic_and_keeps_the_authenticated_session_locked(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->actingAs($user)
            ->withSession($this->lockedSession($user))
            ->post(route('session-lock.unlock'), [
                'method' => 'pin',
                'pin' => '000000',
            ])
            ->assertRedirect(route('session-lock.show'))
            ->assertSessionHasErrors([
                'credential' => 'Les informations fournies sont invalides.',
            ])
            ->assertSessionHas(SessionLockService::SESSION_LOCKED_AT);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.session_unlock_failed',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_unlock_attempts_are_throttled_by_user_session_and_ip(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->actingAs($user)->withSession($this->lockedSession($user));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('session-lock.unlock'), [
                'method' => 'pin',
                'pin' => '000000',
            ])->assertRedirect(route('session-lock.show'));
        }

        $response = $this->withHeader('Accept', 'application/json')
            ->post(route('session-lock.unlock'), [
                'method' => 'pin',
                'pin' => '000000',
            ]);
        $response
            ->assertTooManyRequests()
            ->assertJson([
                'message' => 'Trop de tentatives. Patientez avant de réessayer.',
            ]);

        $this->assertSame(
            5,
            AuditLog::query()->where('action', 'security.session_unlock_failed')->count(),
        );
    }

    public function test_idle_deadline_locks_a_medical_route_and_normal_requests_do_not_count_as_activity(): void
    {
        $user = User::factory()->create();
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);

        $this->actingAs($user)
            ->withSession([
                SessionLockService::SESSION_USER_ID => $user->getKey(),
                SessionLockService::SESSION_LAST_ACTIVITY_AT => $startedAt->timestamp,
            ])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas(
                SessionLockService::SESSION_LAST_ACTIVITY_AT,
                $startedAt->timestamp,
            );

        $this->travel(16)->minutes();

        $this->get(route('dashboard'))
            ->assertRedirect(route('session-lock.show'))
            ->assertSessionHas(SessionLockService::SESSION_LOCK_REASON, 'idle');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.session_locked',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_only_the_activity_endpoint_refreshes_the_real_activity_timestamp(): void
    {
        $user = User::factory()->create();
        $startedAt = now()->startOfSecond();
        $instanceId = str_repeat('B', 40);
        $this->travelTo($startedAt);

        $this->actingAs($user)->withSession([
            SessionLockService::SESSION_USER_ID => $user->getKey(),
            SessionLockService::SESSION_LAST_ACTIVITY_AT => $startedAt->timestamp,
            SessionLockService::SESSION_INSTANCE_ID => $instanceId,
        ]);

        $this->travel(2)->minutes();

        $this->withHeader('X-MediSmart-Session-Instance', $instanceId)
            ->post(route('session-lock.activity'))
            ->assertNoContent()
            ->assertSessionHas(
                SessionLockService::SESSION_LAST_ACTIVITY_AT,
                $startedAt->addMinutes(2)->timestamp,
            );
    }

    public function test_stale_tabs_cannot_refresh_or_lock_a_new_session_instance(): void
    {
        $user = User::factory()->create();
        $startedAt = now()->startOfSecond();
        $currentInstance = str_repeat('C', 40);
        $staleInstance = str_repeat('D', 40);

        $this->actingAs($user)->withSession([
            SessionLockService::SESSION_USER_ID => $user->getKey(),
            SessionLockService::SESSION_LAST_ACTIVITY_AT => $startedAt->timestamp,
            SessionLockService::SESSION_INSTANCE_ID => $currentInstance,
        ]);
        $this->travel(2)->minutes();

        $this->withHeader('X-MediSmart-Session-Instance', $staleInstance)
            ->post(route('session-lock.activity'))
            ->assertStatus(409)
            ->assertSessionHas(
                SessionLockService::SESSION_LAST_ACTIVITY_AT,
                $startedAt->timestamp,
            );

        $this->withHeader('X-Inertia', 'true')
            ->post(route('session-lock.lock-idle'), [
                'intended' => '/dashboard',
                'session_instance_id' => $staleInstance,
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('session-lock.show'))
            ->assertSessionMissing(SessionLockService::SESSION_LOCKED_AT);
    }

    public function test_missing_corrupt_or_future_activity_state_fails_closed(): void
    {
        $user = User::factory()->create();
        $base = [
            SessionLockService::SESSION_USER_ID => $user->getKey(),
            SessionLockService::SESSION_INSTANCE_ID => str_repeat('E', 40),
        ];

        foreach ([null, 'corrupt', now()->addDay()->timestamp] as $activity) {
            $session = $base;

            if ($activity !== null) {
                $session[SessionLockService::SESSION_LAST_ACTIVITY_AT] = $activity;
            }

            $this->actingAs($user)
                ->withSession($session)
                ->get(route('dashboard'))
                ->assertRedirect(route('session-lock.show'))
                ->assertSessionHas(SessionLockService::SESSION_LOCK_REASON, 'idle');

            $this->flushSession();
        }
    }

    public function test_locked_json_and_inertia_requests_are_blocked_without_data_leakage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->lockedSession($user));

        $this->withHeader('Accept', 'application/json')
            ->get(route('dashboard'))
            ->assertStatus(423)
            ->assertExactJson(['message' => 'La session est verrouillée.']);

        $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
            'X-Inertia' => 'true',
        ])->get(route('dashboard'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('session-lock.show'));
    }

    public function test_logout_remains_available_while_locked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->lockedSession($user))
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_unlock_endpoint_cannot_authenticate_a_guest_or_elevate_an_unlocked_session(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->post(route('session-lock.unlock'), [
            'method' => 'pin',
            'pin' => '123456',
        ])->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($user)
            ->post(route('session-lock.unlock'), [
                'method' => 'pin',
                'pin' => '123456',
            ])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'security.session_unlocked',
            'user_id' => $user->getKey(),
        ]);
    }

    /** @return array<string, int|string> */
    private function lockedSession(User $user, string $intended = '/dashboard'): array
    {
        return [
            SessionLockService::SESSION_USER_ID => $user->getKey(),
            SessionLockService::SESSION_LOCKED_AT => now()->timestamp,
            SessionLockService::SESSION_LOCK_REASON => 'manual',
            SessionLockService::SESSION_LAST_ACTIVITY_AT => now()->timestamp,
            SessionLockService::SESSION_INTENDED => $intended,
            SessionLockService::SESSION_INSTANCE_ID => str_repeat('L', 40),
        ];
    }
}
