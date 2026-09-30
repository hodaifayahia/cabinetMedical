<?php

namespace Tests\Feature\Configuration;

use App\Backups\AutomaticBackupCreator;
use App\Backups\AutomaticDriveUploadPolicy;
use App\Backups\EncryptedAutomaticBackupCreator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use SensitiveParameter;
use Tests\TestCase;

/**
 * The local backups of a clinic desktop are mandatory, three times a day, and
 * the clinic's doctor runs them from Configuration: sees when they happen,
 * changes the three times and saves one now. Connectivity and the other
 * installation-wide tools stay out of the doctor's reach.
 */
class DesktopDoctorLocalBackupTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:43123';

    private const SETTINGS_URL = self::ORIGIN.'/app/configuration/connectivity-backup';

    private const BACKUP_NOW_URL = self::ORIGIN.'/app/configuration/backup/now';

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
        Queue::fake();
        $this->managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-doctor-local-'.Str::uuid();
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'services.google.client_id' => 'drclick-test.apps.googleusercontent.com',
            'services.google.client_secret' => null,
            'services.google.redirect' => null,
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
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
        $this->assistant = $this->member('Secrétaire Poste', RoleName::ASSISTANT);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->managedRoot);

        parent::tearDown();
    }

    public function test_the_doctor_sees_the_three_daily_backups_and_where_they_are(): void
    {
        $this->travelTo(now()->setTime(11, 0));
        $this->fakeLocalBackups()->create();

        $this->asMember($this->doctor)
            ->get(self::SETTINGS_URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_backups', true)
                ->where('permissions.manage_settings', false)
                ->where('settings.backups.schedule_times', ['10:00', '14:00', '18:00'])
                ->where('backupSchedule.times', ['10:00', '14:00', '18:00'])
                ->where('backupSchedule.next_at', now()->setTime(14, 0)->toIso8601String())
                ->where('backupSchedule.last_restore_point.filename', 'Drclick-Backup-local-1.msbackup')
                ->where('backupSchedule.location', $this->managedRoot)
                ->where('capabilities.automatic_backups.available', true));

        // Without a configuration permission the page stays closed.
        $this->asMember($this->assistant)->get(self::SETTINGS_URL)->assertForbidden();

        // With another one (Drive), the backup details stay hidden.
        $this->assistant->givePermissionTo('configuration.drive.manage');
        $this->asMember($this->assistant)
            ->get(self::SETTINGS_URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_backups', false)
                ->where('backupSchedule.next_at', null)
                ->where('backupSchedule.last_restore_point', null)
                ->where('backupSchedule.location', null));
    }

    public function test_the_doctor_saves_the_three_times_but_never_the_connectivity_settings(): void
    {
        $this->asMember($this->doctor)
            ->from(self::SETTINGS_URL)
            ->put(self::SETTINGS_URL, [
                'backups' => [
                    'schedule_times' => ['17:30', '09:00', '12:15'],
                    'retention_daily' => 10,
                    'retention_weekly' => 4,
                    'retention_monthly' => 12,
                    'maximum_storage_bytes' => null,
                ],
                // Sent by the page, but not the doctor's to change.
                'connectivity' => ['lan_enabled' => true, 'selected_adapter_id' => 'forged'],
                'updates' => ['auto_check' => false],
            ])
            ->assertRedirect(self::SETTINGS_URL)
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertSame(['09:00', '12:15', '17:30'], $settings->get(Setting::BACKUP_SCHEDULE_TIMES));
        $this->assertSame(10, $settings->get(Setting::BACKUP_RETENTION_DAILY));
        $this->assertFalse($settings->get(Setting::CONNECTIVITY_LAN_ENABLED));
        $this->assertTrue($settings->get(Setting::UPDATE_AUTO_CHECK));

        $this->asMember($this->doctor)
            ->put(self::SETTINGS_URL, [
                'backups' => [
                    'schedule_times' => ['09:00', '09:00', '12:15'],
                    'retention_daily' => 10,
                    'retention_weekly' => 4,
                    'retention_monthly' => 12,
                    'maximum_storage_bytes' => null,
                ],
            ])
            ->assertSessionHasErrors('backups.schedule_times.1');

        $this->asMember($this->assistant)
            ->put(self::SETTINGS_URL, [
                'backups' => ['schedule_times' => ['08:00', '12:00', '16:00']],
            ])
            ->assertForbidden();
        $this->assertSame(['09:00', '12:15', '17:30'], $settings->get(Setting::BACKUP_SCHEDULE_TIMES));
    }

    public function test_the_doctor_saves_a_verified_backup_on_this_pc_now(): void
    {
        $local = $this->fakeLocalBackups();

        $this->asMember($this->doctor)
            ->from(self::SETTINGS_URL)
            ->post(self::BACKUP_NOW_URL)
            ->assertRedirect(self::SETTINGS_URL)
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                'Sauvegarde enregistrée et vérifiée sur ce PC.',
            );

        $this->assertSame(1, $local->calls);
        $this->assertFileExists($this->managedRoot.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.manual_completed',
            'user_id' => $this->doctor->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', ['event' => 'ManualBackupCompleted']);
        // Nothing leaves the PC without the doctor's Drive opt-in.
        Queue::assertNothingPushed();

        $this->asMember($this->assistant)->post(self::BACKUP_NOW_URL)->assertForbidden();
        $this->assertSame(1, $local->calls);
    }

    public function test_a_backup_saved_now_is_also_copied_to_drive_when_the_doctor_opted_in(): void
    {
        $this->fakeLocalBackups();
        app()->instance(EncryptedAutomaticBackupCreator::class, new class implements EncryptedAutomaticBackupCreator
        {
            public function create(#[SensitiveParameter] string $passphrase): BackupRecord
            {
                return BackupRecord::query()->create([
                    'filename' => 'Drclick-Backup-drive-copy.msbackup',
                    'disk' => 'local',
                    'size' => 2048,
                    'sha256' => str_repeat('e', 64),
                    'schema_version' => 2,
                    'application_version' => 'test',
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        });
        DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $this->settings->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
        app(AutomaticDriveUploadPolicy::class)->enable('correct horse battery staple');

        $this->asMember($this->doctor)
            ->post(self::BACKUP_NOW_URL)
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_contains($message, 'part vers Google Drive'),
            );

        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
    }

    public function test_saving_now_is_refused_while_another_backup_is_written_and_a_failure_is_reported(): void
    {
        $local = $this->fakeLocalBackups();
        $lock = Cache::lock('medismart:scheduled-backup', 60);
        $this->assertTrue($lock->get());

        try {
            $this->asMember($this->doctor)
                ->post(self::BACKUP_NOW_URL)
                ->assertSessionHasErrors('backup_now');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $local->calls);

        app()->instance(AutomaticBackupCreator::class, new class implements AutomaticBackupCreator
        {
            public function create(): BackupRecord
            {
                throw new \RuntimeException('Disk full.');
            }
        });

        $this->asMember($this->doctor)
            ->post(self::BACKUP_NOW_URL)
            ->assertSessionHasErrors('backup_now');
        $this->assertDatabaseHas('application_events', [
            'event' => 'ManualBackupFailed',
            'severity' => 'error',
        ]);
    }

    public function test_the_route_writes_only_a_local_archive_behind_the_backup_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('app.configuration.backup.now');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('permission:configuration.backups.manage', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    private function fakeLocalBackups(): object
    {
        $root = $this->managedRoot;
        $creator = new class($root) implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function __construct(private readonly string $root) {}

            public function create(): BackupRecord
            {
                $this->calls++;
                $filename = 'Drclick-Backup-local-'.$this->calls.'.msbackup';
                $path = $this->root.DIRECTORY_SEPARATOR.$filename;
                file_put_contents($path, 'local archive');

                return BackupRecord::query()->create([
                    'filename' => $filename,
                    'disk' => 'local',
                    'local_path' => $path,
                    'size' => 13,
                    'sha256' => str_repeat('a', 64),
                    'schema_version' => 1,
                    'application_version' => 'test',
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        };
        app()->instance(AutomaticBackupCreator::class, $creator);

        return $creator;
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

    private function asMember(User $user): self
    {
        return $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables([
                'HTTP_HOST' => '127.0.0.1:43123',
                'SERVER_NAME' => '127.0.0.1',
                'REMOTE_ADDR' => '127.0.0.1',
                'SERVER_PORT' => 43123,
            ]);
    }
}
