<?php

namespace Tests\Feature\Configuration;

use App\Backups\AutomaticBackupCreator;
use App\Enums\PermissionName;
use App\Models\BackupRecord;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use Tests\TestCase;

class PrepareUpdateInstallControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->givePermissionTo(
            PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value,
        );
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.installation_id' => 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09',
            'medismart.updates.signed_updater_configured' => true,
        ]);
        URL::forceRootUrl('http://127.0.0.1:43123');
        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    public function test_it_creates_a_verified_backup_before_issuing_native_install_authorization(): void
    {
        $backup = BackupRecord::query()->create([
            'filename' => 'MediSmart-Backup-update.msbackup',
            'local_path' => 'backups/MediSmart-Backup-update.msbackup',
            'size' => 4096,
            'sha256' => str_repeat('42', 32),
            'schema_version' => 2,
            'application_version' => '0.1.0',
            'status' => 'completed',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $this->mock(AutomaticBackupCreator::class, function (MockInterface $mock) use ($backup): void {
            $mock->shouldReceive('create')->once()->andReturn($backup);
        });

        $response = $this->actingAs($this->administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('app.configuration.updates.prepare-install'), [
                'target_version' => '1.2.3',
            ]);

        $response->assertOk()
            ->assertJsonPath('authorization.protocol', 'medismart-update-install-authorization')
            ->assertJsonPath('authorization.target_version', '1.2.3')
            ->assertJsonPath('authorization.backup_record_id', $backup->getKey())
            ->assertJsonPath('authorization.installation_id', 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09')
            ->assertJsonPath('backup.id', $backup->getKey());

        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            (string) $response->json('authorization.signature'),
        );
        $this->assertDatabaseHas('backup_records', [
            'id' => $backup->getKey(),
            'created_by' => $this->administrator->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updates.install_prepared',
            'subject_id' => $backup->getKey(),
        ]);
    }

    public function test_it_fails_closed_outside_a_configured_supervised_release(): void
    {
        config(['medismart.updates.signed_updater_configured' => false]);
        $this->mock(AutomaticBackupCreator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('create');
        });

        $this->actingAs($this->administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('app.configuration.updates.prepare-install'), [
                'target_version' => '1.2.3',
            ])
            ->assertConflict();
    }

    public function test_it_requires_recent_password_confirmation(): void
    {
        $this->actingAs($this->administrator)
            ->post(route('app.configuration.updates.prepare-install'), [
                'target_version' => '1.2.3',
            ])
            ->assertRedirect(route('password.confirm'));
    }
}
