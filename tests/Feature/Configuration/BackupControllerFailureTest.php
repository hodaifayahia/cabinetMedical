<?php

namespace Tests\Feature\Configuration;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BackupControllerFailureTest extends TestCase
{
    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $this->actingAs($this->administrator)
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_drive_upload_is_disabled_until_the_transfer_workflow_is_available(): void
    {
        $this->post(route('app.configuration.backup.drive.store'), [
            'folder_name' => 'Drclick Backups',
        ])
            ->assertStatus(503);

        $this->assertDatabaseCount('backup_records', 0);
    }

    public function test_drive_upload_cannot_be_forged_without_a_signed_feature_entitlement(): void
    {
        config([
            'medismart.runtime.queue_worker_status' => 'active',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.remote_upload_url' => null,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => null,
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
        ]);

        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
        ])->post(
            'http://127.0.0.1:43123/app/configuration/backup/drive',
            [
                'folder_name' => 'Drclick Backups',
                'passphrase' => 'correct horse battery staple',
                'passphrase_confirmation' => 'correct horse battery staple',
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('backup_records', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_encrypted_backup_requires_a_confirmed_recovery_passphrase(): void
    {
        $this->post(route('app.configuration.backup.local.encrypted'), [
            'passphrase' => 'correct horse battery staple',
            'passphrase_confirmation' => 'different recovery phrase',
        ])->assertSessionHasErrors('passphrase');

        $this->assertDatabaseCount('backup_records', 0);
    }

    public function test_restore_returns_a_generic_error_instead_of_the_exception_message(): void
    {
        config(['medismart.backups.legacy_restore_enabled' => true]);

        $response = $this->post(route('app.configuration.backup.restore'), [
            'backup' => UploadedFile::fake()->createWithContent(
                'invalid.sqlite3',
                'not a SQLite backup',
            ),
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHasErrors([
                'backup' => 'The backup could not be restored. Please contact support before trying again.',
            ]);

        $this->assertSame(
            ['The backup could not be restored. Please contact support before trying again.'],
            session('errors')->get('backup'),
        );
    }
}
