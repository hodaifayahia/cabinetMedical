<?php

namespace Tests\Feature\Api\Mobile\Notifications;

use App\Models\User;
use App\Notifications\Mobile\AppointmentStatusChanged;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Device push-token registration and the in-app notification inbox.
 * Both endpoints are shared by every mobile role and must stay strictly
 * scoped to the authenticated account.
 */
class DeviceAndInboxTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_device_registration_creates_a_row_for_the_authenticated_user(): void
    {
        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/devices', [
            'token' => 'ExponentPushToken[device-and-inbox-1]',
            'platform' => 'android',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_push_tokens', [
            'token' => 'ExponentPushToken[device-and-inbox-1]',
            'user_id' => $patient->getKey(),
            'platform' => 'android',
        ]);
    }

    public function test_registering_the_same_token_again_updates_instead_of_duplicating(): void
    {
        $patient = $this->makePatientUser();
        Sanctum::actingAs($patient);

        $token = 'ExponentPushToken[device-and-inbox-2]';

        $this->postJson('/api/v1/devices', ['token' => $token, 'platform' => 'android'])
            ->assertStatus(201);
        $this->postJson('/api/v1/devices', ['token' => $token, 'platform' => 'ios'])
            ->assertStatus(200);

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', [
            'token' => $token,
            'user_id' => $patient->getKey(),
            'platform' => 'ios',
        ]);
    }

    public function test_a_token_is_claimed_by_the_latest_account_that_registers_it(): void
    {
        $first = $this->makePatientUser();
        $second = $this->makePatientUser();
        $token = 'ExponentPushToken[device-and-inbox-3]';

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/devices', ['token' => $token])->assertStatus(201);

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/devices', ['token' => $token])->assertStatus(200);

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', [
            'token' => $token,
            'user_id' => $second->getKey(),
        ]);
    }

    public function test_staff_accounts_can_register_devices_too(): void
    {
        ['doctorUser' => $doctorUser] = $this->makeListedClinic();
        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/v1/devices', [
            'token' => 'ExponentPushToken[device-and-inbox-4]',
            'platform' => 'ios',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_push_tokens', [
            'token' => 'ExponentPushToken[device-and-inbox-4]',
            'user_id' => $doctorUser->getKey(),
        ]);
    }

    public function test_device_registration_validates_token_and_platform(): void
    {
        Sanctum::actingAs($this->makePatientUser());

        $this->postJson('/api/v1/devices', ['platform' => 'android'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/devices', [
            'token' => 'ExponentPushToken[device-and-inbox-5]',
            'platform' => 'windows',
        ])->assertStatus(422)->assertJsonValidationErrors('platform');
    }

    public function test_deleting_a_device_only_removes_the_callers_own_row(): void
    {
        $owner = $this->makePatientUser();
        $other = $this->makePatientUser();

        $ownDevice = $owner->devicePushTokens()->create([
            'token' => 'ExponentPushToken[device-and-inbox-6]',
            'platform' => 'android',
        ]);
        $foreignDevice = $other->devicePushTokens()->create([
            'token' => 'ExponentPushToken[device-and-inbox-7]',
            'platform' => 'ios',
        ]);

        Sanctum::actingAs($owner);

        // Another account's token: the call succeeds but removes nothing.
        $this->deleteJson('/api/v1/devices', ['token' => $foreignDevice->token])
            ->assertStatus(200);
        $this->assertDatabaseHas('device_push_tokens', ['id' => $foreignDevice->getKey()]);

        $this->deleteJson('/api/v1/devices', ['token' => $ownDevice->token])
            ->assertStatus(200);
        $this->assertDatabaseMissing('device_push_tokens', ['id' => $ownDevice->getKey()]);
    }

    public function test_device_and_inbox_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/devices', ['token' => 'x'])->assertStatus(401);
        $this->deleteJson('/api/v1/devices', ['token' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read', ['all' => true])->assertStatus(401);
    }

    public function test_inbox_lists_only_own_notifications_latest_first(): void
    {
        $patient = $this->makePatientUser();
        $stranger = $this->makePatientUser();

        $oldest = $this->seedNotification($patient, ['created_at' => now()->subMinutes(10)]);
        $newest = $this->seedNotification($patient, ['created_at' => now()->subMinute()]);
        $foreign = $this->seedNotification($stranger, ['created_at' => now()]);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->getKey())
            ->assertJsonPath('data.0.type', 'AppointmentStatusChanged')
            ->assertJsonPath('data.1.id', $oldest->getKey())
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure([
                'data' => [['id', 'type', 'data', 'read_at', 'created_at']],
                'links',
                'meta',
            ]);

        $this->assertStringNotContainsString($foreign->getKey(), $response->getContent());
    }

    public function test_mark_read_by_ids_touches_only_the_given_rows(): void
    {
        $patient = $this->makePatientUser();
        $target = $this->seedNotification($patient);
        $untouched = $this->seedNotification($patient);

        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/notifications/read', ['ids' => [$target->getKey()]])
            ->assertStatus(200)
            ->assertJsonPath('updated', 1);

        $this->assertNotNull($target->fresh()->read_at);
        $this->assertNull($untouched->fresh()->read_at);
    }

    public function test_mark_read_all_marks_every_unread_notification(): void
    {
        $patient = $this->makePatientUser();
        $this->seedNotification($patient);
        $this->seedNotification($patient);
        $alreadyRead = $this->seedNotification($patient, ['read_at' => now()->subHour()]);

        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/notifications/read', ['all' => true])
            ->assertStatus(200)
            ->assertJsonPath('updated', 2);

        $this->assertSame(0, $patient->unreadNotifications()->count());
        $this->assertNotNull($alreadyRead->fresh()->read_at);
    }

    public function test_mark_read_cannot_touch_another_users_notifications(): void
    {
        $patient = $this->makePatientUser();
        $stranger = $this->makePatientUser();
        $foreign = $this->seedNotification($stranger);

        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/notifications/read', ['ids' => [$foreign->getKey()]])
            ->assertStatus(200)
            ->assertJsonPath('updated', 0);

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_mark_read_requires_ids_or_all(): void
    {
        Sanctum::actingAs($this->makePatientUser());

        $this->postJson('/api/v1/notifications/read', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids', 'all']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedNotification(User $user, array $overrides = []): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->create(array_merge([
            'id' => (string) Str::uuid(),
            'type' => AppointmentStatusChanged::class,
            'data' => [
                'appointment_public_id' => (string) Str::uuid(),
                'status' => 'confirmed',
                'starts_at' => now()->addDay()->toIso8601String(),
                'doctor_name' => 'Dr Salah',
                'clinic_name' => 'Cabinet El Chifa',
                'changed_by_role' => 'doctor',
            ],
            'read_at' => null,
        ], $overrides));

        return $notification;
    }
}
