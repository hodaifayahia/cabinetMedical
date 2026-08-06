<?php

namespace Tests\Feature\Configuration;

use App\Enums\RoleName;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\CloudConnection;
use App\Models\DriveBackupConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $this->actingAs($this->administrator)
            ->withSession(['auth.password_confirmed_at' => time()]);
        Http::preventStrayRequests();
    }

    public function test_disconnect_revokes_the_provider_token_and_deletes_local_credentials(): void
    {
        $this->connectedDrive();
        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $this->delete(route('app.configuration.backup.google.disconnect'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://oauth2.googleapis.com/revoke'
            && $request->method() === 'POST'
            && $request['token'] === 'drive-access-token');
        $this->assertDatabaseCount('drive_backup_connections', 0);
        $this->assertDatabaseHas('cloud_connections', [
            'provider' => 'google_drive',
            'status' => 'disconnected',
            'account_email' => null,
            'encrypted_access_token' => null,
            'encrypted_refresh_token' => null,
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_disconnected',
            'user_id' => $this->administrator->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'CloudDriveDisconnected',
            'severity' => 'info',
        ]);

        $history = json_encode([
            AuditLog::query()->where('action', 'backup.drive_disconnected')->sole()->metadata,
            ApplicationEvent::query()->where('event', 'CloudDriveDisconnected')->sole()->context,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('drive-access-token', $history);
        $this->assertStringNotContainsString('drive-refresh-token', $history);
    }

    public function test_provider_outage_does_not_prevent_local_credential_deletion(): void
    {
        $this->connectedDrive();
        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $this->delete(route('app.configuration.backup.google.disconnect'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('drive_backup_connections', 0);
        $connection = CloudConnection::query()->where('provider', 'google_drive')->sole();
        $this->assertSame('disconnected', $connection->status);
        $this->assertSame(
            'Remote token revocation could not be confirmed; local credentials were deleted.',
            $connection->last_error,
        );
        $this->assertFalse((bool) data_get(
            AuditLog::query()->where('action', 'backup.drive_disconnected')->sole()->metadata,
            'remote_revocation_confirmed',
        ));
    }

    private function connectedDrive(): DriveBackupConnection
    {
        $cabinet = CabinetSetting::current();
        CloudConnection::query()->create([
            'provider' => 'google_drive',
            'account_email' => 'doctor@example.test',
            'status' => 'connected',
        ]);

        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $cabinet->getKey(),
            'email' => 'doctor@example.test',
            'folder_name' => 'MediSmart Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
