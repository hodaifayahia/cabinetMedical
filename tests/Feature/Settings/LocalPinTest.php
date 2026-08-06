<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_confirmed_user_can_configure_a_hashed_local_pin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('security.local-pin.store'), [
                'pin' => '123456',
                'pin_confirmation' => '123456',
            ])
            ->assertRedirect(route('security.edit'))
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotSame('123456', $user->local_pin_hash);
        $this->assertTrue(Hash::check('123456', $user->local_pin_hash));
        $this->assertArrayNotHasKey('local_pin_hash', $user->toArray());

        $audit = AuditLog::query()
            ->where('action', 'security.local_pin_set')
            ->where('user_id', $user->getKey())
            ->sole();
        $serializedAudit = $audit->toJson();

        $this->assertStringNotContainsString('123456', $serializedAudit);
        $this->assertStringNotContainsString($user->local_pin_hash, $serializedAudit);
    }

    public function test_pin_configuration_requires_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('security.local-pin.store'), [
                'pin' => '123456',
                'pin_confirmation' => '123456',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->refresh()->local_pin_hash);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'security.local_pin_set',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_pin_must_be_six_to_twelve_ascii_digits(): void
    {
        $user = User::factory()->create();

        foreach (['12345', '1234567890123', '１２３４５６', '12345a'] as $invalidPin) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->post(route('security.local-pin.store'), [
                    'pin' => $invalidPin,
                    'pin_confirmation' => $invalidPin,
                ])
                ->assertSessionHasErrors('pin');
        }

        $this->assertNull($user->refresh()->local_pin_hash);
    }

    public function test_a_confirmed_user_can_change_and_remove_the_pin_with_redacted_audits(): void
    {
        $user = User::factory()->create([
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('security.local-pin.store'), [
                'pin' => '654321',
                'pin_confirmation' => '654321',
            ])
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('654321', $user->refresh()->local_pin_hash));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.local_pin_changed',
            'user_id' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('security.local-pin.destroy'))
            ->assertRedirect(route('security.edit'));

        $this->assertNull($user->refresh()->local_pin_hash);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.local_pin_removed',
            'user_id' => $user->getKey(),
        ]);

        $serializedAudits = AuditLog::query()
            ->whereIn('action', ['security.local_pin_changed', 'security.local_pin_removed'])
            ->get()
            ->toJson();
        $this->assertStringNotContainsString('654321', $serializedAudits);
    }

    public function test_a_local_pin_is_never_accepted_as_the_primary_login_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('real-password'),
            'local_pin_hash' => Hash::make('123456'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => '123456',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
