<?php

namespace Tests\Feature\Configuration;

use App\Backups\AutomaticDriveUploadPolicy;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\GoogleDriveOAuthAttempt;
use App\Models\License;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * On a supervised desktop the Google Drive copy is optional and belongs to
 * the clinic's doctor: only the doctor connects, changes or disconnects the
 * account and sets the passphrase of the automatic copies. The local backups
 * are the required ones, and the doctor is reminded while none is recent.
 *
 * No test here injects a signed machine certificate: a clinic desktop is
 * activated through its cabinet plan, and that must be enough.
 */
class DriveBackupDoctorControlTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:43123';

    private const PASSPHRASE = 'correct horse battery staple';

    private const AUTOMATIC_URL = self::ORIGIN.'/app/configuration/backup/drive/automatic';

    private Cabinet $cabinet;

    private CabinetSetting $settings;

    private User $doctor;

    private User $assistant;

    private string $managedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-doctor-backups-'.Str::uuid();
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'services.google.client_id' => 'drclick-test.apps.googleusercontent.com',
            'services.google.client_secret' => null,
            'services.google.redirect' => null,
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
            'medismart.runtime.remote_upload_url' => null,
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6',
            'medismart.runtime.scheduler_status' => 'active',
            'medismart.runtime.queue_worker_status' => 'active',
        ]);

        $this->cabinet = Cabinet::query()->create([
            'name' => 'Cabinet du poste',
            'status' => CabinetStatus::PENDING,
        ]);
        $this->settings = CabinetSetting::current($this->cabinet);
        $this->doctor = $this->member('Docteur Poste', RoleName::DOCTOR);
        $this->cabinet->forceFill(['owner_user_id' => $this->doctor->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($this->cabinet, LicensePlan::LIFETIME);

        // A doctor may hand the Drive permission to a secretary; it still
        // must not let her take over the clinic's Google account.
        $this->assistant = $this->member('Secrétaire Poste', RoleName::ASSISTANT);
        $this->assistant->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->managedRoot);

        parent::tearDown();
    }

    public function test_the_automatic_drive_route_keeps_every_authentication_boundary(): void
    {
        $route = app('router')->getRoutes()->getByName('app.configuration.backup.drive.automatic');

        $this->assertInstanceOf(Route::class, $route);
        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'verified', 'permission:configuration.drive.manage', 'password.confirm'] as $required) {
            $this->assertContains($required, $middleware);
        }
    }

    public function test_the_desktop_doctor_manages_and_controls_drive_while_the_assistant_cannot(): void
    {
        $this->asMember($this->doctor)
            ->get(self::ORIGIN.'/app/configuration/connectivity-backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_drive', true)
                ->where('permissions.control_drive', true)
                ->where('permissions.sensitive_actions_confirmed', true)
                // The machine's local backups are the doctor's to run too.
                ->where('permissions.manage_backups', true)
                ->where('permissions.manage_license', false)
                // The cabinet's plan covers the optional Drive copy.
                ->where('capabilities.google_drive.available', true)
                ->missing('driveAutomation.required')
                ->where('driveAutomation.enabled', false)
                ->where('driveAutomation.scheduler_active', true));

        $this->asMember($this->assistant)
            ->get(self::ORIGIN.'/app/configuration/connectivity-backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_drive', false)
                ->where('permissions.control_drive', false)
                ->where('permissions.manage_backups', false)
                ->where('backupReminder', null)
                ->where('backup.google_drive_connected', false)
                ->where('backup.google_drive_email', null));
    }

    public function test_the_desktop_doctor_can_start_and_complete_the_google_connection(): void
    {
        $response = $this->asMember($this->doctor)
            ->postJson(self::ORIGIN.'/app/configuration/backup/google/prepare')
            ->assertOk();
        $attempt = GoogleDriveOAuthAttempt::query()->sole();
        $this->assertSame($this->doctor->getKey(), $attempt->actor_id);
        $this->assertSame($this->settings->getKey(), $attempt->cabinet_setting_id);

        parse_str((string) parse_url((string) $response->json('authorization_url'), PHP_URL_QUERY), $query);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'cabinet@example.test'],
            ]),
        ]);
        auth()->logout();

        $this->local()
            ->get(self::ORIGIN.'/app/configuration/backup/google/callback?'.http_build_query([
                'code' => 'authorization-code',
                'state' => $query['state'] ?? '',
            ]))
            ->assertOk();

        $connection = DriveBackupConnection::query()->sole();
        $this->assertSame($this->settings->getKey(), $connection->cabinet_setting_id);
        $this->assertSame('cabinet@example.test', $connection->email);
    }

    public function test_the_assistant_is_refused_every_account_and_passphrase_action_even_with_the_drive_permission(): void
    {
        $this->connectDrive();
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);

        $this->asMember($this->assistant)
            ->postJson(self::ORIGIN.'/app/configuration/backup/google/prepare')
            ->assertForbidden();
        $this->asMember($this->assistant)
            ->delete(self::ORIGIN.'/app/configuration/backup/google')
            ->assertForbidden();
        $this->asMember($this->assistant)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => 'an assistant chosen phrase',
                'passphrase_confirmation' => 'an assistant chosen phrase',
            ])
            ->assertForbidden();
        $this->asMember($this->assistant)
            ->put(self::AUTOMATIC_URL, ['enabled' => true])
            ->assertForbidden();
        $this->asMember($this->assistant)
            ->put(self::AUTOMATIC_URL, ['enabled' => false])
            ->assertForbidden();
        $this->asMember($this->assistant)
            ->get(self::ORIGIN.'/app/configuration/connectivity-backup/confirm-sensitive-actions')
            ->assertForbidden();

        $this->assertDatabaseCount('google_drive_oauth_attempts', 0);
        $this->assertNotNull(DriveBackupConnection::query()->sole()->refresh_token);
        $this->assertSame(self::PASSPHRASE, app(AutomaticDriveUploadPolicy::class)->passphrase());
    }

    public function test_the_doctor_sets_changes_and_removes_the_automatic_passphrase(): void
    {
        $this->connectDrive();
        $policy = app(AutomaticDriveUploadPolicy::class);

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => self::PASSPHRASE,
                'passphrase_confirmation' => 'something else entirely',
            ])
            ->assertSessionHasErrors('passphrase');
        $this->assertFalse($policy->enabled());

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => self::PASSPHRASE,
                'passphrase_confirmation' => self::PASSPHRASE,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(self::PASSPHRASE, $policy->passphrase());
        $enabled = AuditLog::query()->where('action', 'backup.drive_automatic_enabled')->sole();
        $this->assertSame($this->doctor->getKey(), $enabled->user_id);
        $this->assertFalse($enabled->metadata['rotated']);
        $this->assertStringNotContainsString(self::PASSPHRASE, json_encode($enabled->metadata, JSON_THROW_ON_ERROR));

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => 'a brand new clinic phrase',
                'passphrase_confirmation' => 'a brand new clinic phrase',
            ])
            ->assertSessionHasNoErrors()
            // Nothing already on Drive is re-encrypted: say so.
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_contains($message, 'restent chiffrées avec l’ancienne phrase secrète'),
            );

        $this->assertSame('a brand new clinic phrase', $policy->passphrase());
        $this->assertSame([false, true], AuditLog::query()
            ->where('action', 'backup.drive_automatic_enabled')
            ->get()
            ->map(fn (AuditLog $log): bool => (bool) $log->metadata['rotated'])
            ->sort()
            ->values()
            ->all());

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, ['enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($policy->enabled());
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.drive_automatic_disabled']);
    }

    public function test_enabling_the_copy_again_without_a_passphrase_keeps_the_stored_one(): void
    {
        $this->connectDrive();
        $policy = app(AutomaticDriveUploadPolicy::class);
        $policy->enable(self::PASSPHRASE);

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, ['enabled' => true])
            ->assertSessionHasErrors('passphrase');

        // No silent rotation: older Drive archives keep matching it.
        $this->assertSame(self::PASSPHRASE, $policy->passphrase());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'backup.drive_automatic_enabled']);
    }

    public function test_automatic_copies_need_a_cabinet_plan_and_a_connected_drive(): void
    {
        // A cabinet backfilled without a hosted plan, and no machine
        // certificate: nothing covers the Drive backup.
        $licenceId = $this->withoutHostedPlan();

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => self::PASSPHRASE,
                'passphrase_confirmation' => self::PASSPHRASE,
            ])
            ->assertForbidden();

        $this->cabinet->forceFill(['license_id' => $licenceId])->save();

        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => self::PASSPHRASE,
                'passphrase_confirmation' => self::PASSPHRASE,
            ])
            ->assertStatus(409);

        $this->assertFalse(app(AutomaticDriveUploadPolicy::class)->enabled());
    }

    public function test_disconnecting_drive_also_forgets_the_automatic_passphrase(): void
    {
        $this->connectDrive();
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        Http::fake();

        $this->asMember($this->doctor)
            ->delete(self::ORIGIN.'/app/configuration/backup/google')
            ->assertRedirect();

        $this->assertNull(DriveBackupConnection::query()->first()?->refresh_token);
        $this->assertFalse(app(AutomaticDriveUploadPolicy::class)->enabled());
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE]);
    }

    public function test_the_reminder_follows_the_local_backups_while_drive_stays_optional(): void
    {
        // Nothing saved on this PC yet: the required backup is missing.
        $this->assertReminder($this->doctor, 'local_missing');
        $this->assertReminder($this->assistant, null);

        // A day and more without a backup: they stopped.
        $old = $this->restorePoint(now()->subHours(30));
        $this->assertReminder($this->doctor, 'local_overdue');

        $this->restorePoint(now()->subHours(2));
        $this->assertReminder($this->doctor, null);

        // An archive gone from the PC does not count as a backup.
        $this->assertTrue(unlink((string) BackupRecord::query()->latest('started_at')->first()?->local_path));
        $this->assertReminder($this->doctor, 'local_overdue');
        $this->assertFileExists((string) $old->local_path);

        $this->restorePoint(now()->subMinutes(5));

        // No Google client, no plan, no account, no automatic copy: Drive is
        // optional, so none of that is a reason to nag the doctor.
        config(['services.google.client_id' => null]);
        $this->assertReminder($this->doctor, null);
        config(['services.google.client_id' => 'drclick-test.apps.googleusercontent.com']);
        $this->withoutHostedPlan();
        $this->assertReminder($this->doctor, null);
    }

    public function test_the_reminder_comes_back_when_the_copies_stop_reaching_drive(): void
    {
        $this->connectDrive();
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->restorePoint(now()->subMinutes(10));

        // An expired or revoked grant, or a machine left offline: the copy
        // gave up after its retries while the grant still looks connected.
        $this->driveCopy(BackupRecord::DRIVE_UPLOAD_FAILED, now()->subHours(50));
        $this->assertReminder($this->doctor, 'drive_failing');

        $this->driveCopy(BackupRecord::DRIVE_UPLOAD_COMPLETED, now()->subHours(49), 'remote-file-id');
        $this->assertReminder($this->doctor, null);

        // A queue that never runs: the next copy has not moved for a day.
        $stalled = $this->driveCopy(BackupRecord::DRIVE_UPLOAD_QUEUED, now()->subHours(25));
        $this->assertReminder($this->doctor, 'drive_failing');

        $stalled->forceFill([
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_UPLOADING,
            'drive_upload_updated_at' => now()->subMinutes(5),
        ])->save();
        $this->assertReminder($this->doctor, null);

        $this->driveCopy(BackupRecord::DRIVE_UPLOAD_COMPLETED, now(), 'second-remote-file-id');
        $this->assertReminder($this->doctor, null);

        // A second grant on the machine: the daily copy refuses to choose.
        $legacy = CabinetSetting::query()->create([
            ...CabinetSetting::defaults(),
            'name' => 'Ancienne installation',
        ]);
        $this->connectDrive($legacy);
        $this->assertReminder($this->doctor, 'drive_failing');

        // The doctor turns the optional copy off: the local backups suffice.
        app(AutomaticDriveUploadPolicy::class)->disable();
        $this->assertReminder($this->doctor, null);
    }

    public function test_no_reminder_off_the_supervised_desktop(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->actingAs($this->doctor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('backupReminder', null));
    }

    public function test_a_second_cabinet_on_the_machine_keeps_every_doctor_out_of_the_installation_drive(): void
    {
        $this->connectDrive();
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $other = Cabinet::query()->create([
            'name' => 'Autre cabinet',
            'status' => CabinetStatus::PENDING,
        ]);
        $this->assertNotSame($this->cabinet->getKey(), $other->getKey());
        $otherSettings = CabinetSetting::current($other);
        $otherDoctor = User::factory()->create([
            'cabinet_id' => $other->getKey(),
            'cabinet_setting_id' => $otherSettings->getKey(),
            'approved_at' => now(),
        ]);
        $otherDoctor->assignRole(RoleName::DOCTOR->value);
        $other->forceFill(['owner_user_id' => $otherDoctor->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($other, LicensePlan::LIFETIME);

        // A shared machine's backups belong to its installation maintainer,
        // not to either clinic's doctor.
        $this->asMember($this->doctor)
            ->get(self::ORIGIN.'/app/configuration/connectivity-backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_drive', false)
                ->where('permissions.control_drive', false)
                ->where('permissions.manage_backups', false)
                ->where('backupReminder', null));
        $this->asMember($this->doctor)
            ->postJson(self::ORIGIN.'/app/configuration/backup/google/prepare')
            ->assertForbidden();
        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, [
                'enabled' => true,
                'passphrase' => 'a brand new clinic phrase',
                'passphrase_confirmation' => 'a brand new clinic phrase',
            ])
            ->assertForbidden();

        // Stopping stays open, but only to the doctor whose cabinet holds the
        // grant. (A user switch drops the password confirmation on its first
        // request, so each actor opens the page first.)
        $this->assertReminder($this->assistant, null);
        $this->asMember($this->assistant)
            ->put(self::AUTOMATIC_URL, ['enabled' => false])
            ->assertForbidden();
        $this->assertReminder($otherDoctor, null);
        $this->asMember($otherDoctor)
            ->put(self::AUTOMATIC_URL, ['enabled' => false])
            ->assertForbidden();
        $this->assertTrue(app(AutomaticDriveUploadPolicy::class)->enabled());

        $this->assertReminder($this->doctor, null);
        $this->asMember($this->doctor)
            ->put(self::AUTOMATIC_URL, ['enabled' => false])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertFalse(app(AutomaticDriveUploadPolicy::class)->enabled());

        Http::fake();
        $this->asMember($this->doctor)
            ->delete(self::ORIGIN.'/app/configuration/backup/google')
            ->assertRedirect();
        $this->assertNull(DriveBackupConnection::query()->first()?->refresh_token);
    }

    public function test_off_the_desktop_the_drive_permission_manages_but_only_the_doctor_role_controls(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        // A legacy single installation: accounts without a cabinet.
        $manager = User::factory()->create();
        $manager->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_drive', true)
                ->where('permissions.control_drive', false)
                ->where('permissions.sensitive_actions_confirmed', true));
        $this->postJson(route('app.configuration.backup.google.prepare'))
            ->assertForbidden();
        $this->delete(route('app.configuration.backup.google.disconnect'))
            ->assertForbidden();
        $this->put(route('app.configuration.backup.drive.automatic'), ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_drive', true)
                ->where('permissions.control_drive', true));
    }

    private function assertReminder(User $user, ?string $state): void
    {
        $this->asMember($user)
            ->get(self::ORIGIN.'/app/configuration/connectivity-backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $state === null
                ? $page->where('backupReminder', null)
                : $page->where('backupReminder.state', $state));
    }

    /** A verified local backup whose archive is on this PC. */
    private function restorePoint(CarbonInterface $startedAt): BackupRecord
    {
        $path = $this->managedRoot.DIRECTORY_SEPARATOR.'Drclick-Backup-'.Str::uuid().'.msbackup';
        file_put_contents($path, 'local archive');

        return BackupRecord::query()->create([
            'filename' => basename($path),
            'disk' => 'local',
            'local_path' => $path,
            'size' => 13,
            'sha256' => str_repeat('d', 64),
            'schema_version' => 1,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    /**
     * Detach the cabinet's hosted plan, as for a cabinet backfilled without
     * one: it stays usable, and only a machine certificate could cover Drive.
     * Returns the plan's id so a test can reattach it.
     */
    private function withoutHostedPlan(): int
    {
        $licence = $this->cabinet->fresh()?->license;
        $this->assertInstanceOf(License::class, $licence);
        $this->cabinet->forceFill(['license_id' => null])->save();

        return (int) $licence->getKey();
    }

    private function member(string $name, RoleName $role): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => $this->settings->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    private function connectDrive(?CabinetSetting $settings = null): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => ($settings ?? $this->settings)->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function driveCopy(string $status, CarbonInterface $at, ?string $remoteFileId = null): BackupRecord
    {
        return BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-'.Str::lower(Str::random(8)).'.msbackup',
            'disk' => 'local',
            'size' => 2048,
            'sha256' => str_repeat('b', 64),
            'schema_version' => 2,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => $at,
            'completed_at' => $at,
            'remote_file_id' => $remoteFileId,
            'drive_upload_status' => $status,
            'drive_upload_updated_at' => $at,
        ]);
    }

    private function asMember(User $user): self
    {
        return $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->local();
    }

    private function local(): self
    {
        return $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ]);
    }
}
