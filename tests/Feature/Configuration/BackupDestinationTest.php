<?php

namespace Tests\Feature\Configuration;

use App\Backups\AutomaticBackupCreator;
use App\Backups\EncryptedAutomaticBackupCreator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\ApplicationEvent;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use SensitiveParameter;
use Tests\TestCase;

/**
 * The doctor chooses a second folder (another disk, a USB key) that receives
 * a verified copy of every backup, sees the backups present on this PC and
 * downloads one.
 */
class BackupDestinationTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:43123';

    private const SETTINGS_URL = self::ORIGIN.'/app/configuration/connectivity-backup';

    private const DESTINATION_URL = self::ORIGIN.'/app/configuration/backup/destination';

    private const BACKUP_NOW_URL = self::ORIGIN.'/app/configuration/backup/now';

    private const DOWNLOAD_URL = self::ORIGIN.'/app/configuration/backup/archives/download';

    private Cabinet $cabinet;

    private User $doctor;

    private User $assistant;

    private string $workspace;

    private string $managedRoot;

    private string $copyFolder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Queue::fake();
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-destination-'.Str::uuid();
        $this->managedRoot = $this->workspace.DIRECTORY_SEPARATOR.'managed';
        $this->copyFolder = $this->workspace.DIRECTORY_SEPARATOR.'usb'.DIRECTORY_SEPARATOR.'Sauvegardes Drclick';
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6',
            'medismart.runtime.scheduler_status' => 'active',
            'medismart.runtime.queue_worker_status' => 'active',
        ]);

        $this->cabinet = Cabinet::query()->create(['name' => 'Cabinet du poste', 'status' => CabinetStatus::PENDING]);
        $this->doctor = $this->member('Docteur Poste', RoleName::DOCTOR);
        $this->cabinet->forceFill(['owner_user_id' => $this->doctor->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($this->cabinet, LicensePlan::LIFETIME);
        $this->assistant = $this->member('Secrétaire Poste', RoleName::ASSISTANT);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_every_backup_is_also_copied_and_verified_in_the_chosen_folder(): void
    {
        $this->fakeLocalBackups();

        $this->asMember($this->doctor)
            ->from(self::SETTINGS_URL)
            ->put(self::DESTINATION_URL, ['copy_directory' => $this->copyFolder, 'copy_keep' => 5])
            ->assertRedirect(self::SETTINGS_URL)
            ->assertSessionHasNoErrors();

        // A missing folder is created when it is chosen.
        $this->assertDirectoryExists($this->copyFolder);
        $this->assertSame($this->copyFolder, app(ApplicationSettingService::class)->get(Setting::BACKUP_COPY_DIRECTORY));

        $this->asMember($this->doctor)
            ->from(self::SETTINGS_URL)
            ->post(self::BACKUP_NOW_URL)
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_contains($message, 'Copie vérifiée dans le dossier choisi.'),
            );

        $copy = $this->copyFolder.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup';
        $this->assertFileExists($copy);
        $this->assertSame(
            hash_file('sha256', $this->managedRoot.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup'),
            hash_file('sha256', $copy),
        );

        $this->asMember($this->doctor)
            ->get(self::SETTINGS_URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('backupDestination.internal_location', $this->managedRoot)
                ->where('backupDestination.copy_directory', $this->copyFolder)
                ->where('backupDestination.copy_keep', 5)
                ->where('backupDestination.last_copy.status', 'success')
                ->where('backupDestination.last_copy.filename', 'Drclick-Backup-local-1.msbackup')
                ->where('permissions.restore_backups', true)
                ->has('latestBackups', 1)
                ->where('latestBackups.0.key', 'Drclick-Backup-local-1.msbackup')
                ->where('latestBackups.0.kind', 'manual')
                ->where('latestBackups.0.verified', true)
                ->where('latestBackups.0.encrypted', false)
                ->where('latestBackups.0.in_copy_folder', true));
    }

    public function test_only_the_newest_copies_are_kept_and_other_files_are_never_touched(): void
    {
        $this->fakeLocalBackups();
        File::ensureDirectoryExists($this->copyFolder);
        file_put_contents($this->copyFolder.DIRECTORY_SEPARATOR.'notes.txt', 'mes notes');
        app(ApplicationSettingService::class)->setMany([
            Setting::BACKUP_COPY_DIRECTORY => $this->copyFolder,
            Setting::BACKUP_COPY_KEEP => 2,
        ]);

        foreach ([1, 2, 3] as $run) {
            $this->travel(1)->minutes();
            $this->asMember($this->doctor)->post(self::BACKUP_NOW_URL)->assertSessionHasNoErrors();
            touch($this->copyFolder.DIRECTORY_SEPARATOR.'Drclick-Backup-local-'.$run.'.msbackup', time() + $run * 60);
        }

        $this->assertFileDoesNotExist($this->copyFolder.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup');
        $this->assertFileExists($this->copyFolder.DIRECTORY_SEPARATOR.'Drclick-Backup-local-2.msbackup');
        $this->assertFileExists($this->copyFolder.DIRECTORY_SEPARATOR.'Drclick-Backup-local-3.msbackup');
        $this->assertFileExists($this->copyFolder.DIRECTORY_SEPARATOR.'notes.txt');
        // The local backups themselves follow their own retention, untouched.
        $this->assertFileExists($this->managedRoot.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup');
    }

    public function test_a_failed_copy_never_fails_the_backup_and_is_reported(): void
    {
        $local = $this->fakeLocalBackups();
        app(ApplicationSettingService::class)->set(Setting::BACKUP_COPY_DIRECTORY, $this->copyFolder);
        // The USB key was replaced by something that is not a folder.
        File::ensureDirectoryExists(dirname($this->copyFolder));
        file_put_contents($this->copyFolder, 'not a folder');

        $this->asMember($this->doctor)
            ->from(self::SETTINGS_URL)
            ->post(self::BACKUP_NOW_URL)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'warning')
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_starts_with($message, 'Sauvegarde enregistrée et vérifiée sur ce PC.')
                    && str_contains($message, 'copie vers le dossier choisi a échoué'),
            );

        $this->assertSame(1, $local->calls);
        $this->assertFileExists($this->managedRoot.DIRECTORY_SEPARATOR.'Drclick-Backup-local-1.msbackup');
        $this->assertSame('failed', app(ApplicationSettingService::class)->get(Setting::BACKUP_COPY_LAST_RESULT)['status']);
        $this->assertDatabaseHas('application_events', ['event' => 'BackupCopyFailed', 'severity' => 'warning']);
    }

    public function test_an_unusable_folder_is_refused_and_can_be_tested_first(): void
    {
        $file = $this->workspace.DIRECTORY_SEPARATOR.'a-file.txt';
        file_put_contents($file, 'x');

        foreach ([
            'Sauvegardes',
            $this->managedRoot.DIRECTORY_SEPARATOR.'copies',
            storage_path('app/private/elsewhere'),
            $file,
        ] as $folder) {
            $this->asMember($this->doctor)
                ->put(self::DESTINATION_URL, ['copy_directory' => $folder, 'copy_keep' => 5])
                ->assertSessionHasErrors('copy_directory');
        }

        $this->assertNull(app(ApplicationSettingService::class)->get(Setting::BACKUP_COPY_DIRECTORY));
        $this->assertDirectoryDoesNotExist($this->managedRoot.DIRECTORY_SEPARATOR.'copies');

        $this->asMember($this->doctor)
            ->postJson(self::DESTINATION_URL.'/test', ['copy_directory' => $file])
            ->assertOk()
            ->assertJson(['ok' => false]);
        $this->asMember($this->doctor)
            ->postJson(self::DESTINATION_URL.'/test', ['copy_directory' => $this->copyFolder])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Clearing the folder goes back to the managed directory only.
        app(ApplicationSettingService::class)->set(Setting::BACKUP_COPY_DIRECTORY, $this->copyFolder);
        $this->asMember($this->doctor)
            ->put(self::DESTINATION_URL, ['copy_directory' => '', 'copy_keep' => 5])
            ->assertSessionHasNoErrors();
        $this->assertNull(app(ApplicationSettingService::class)->get(Setting::BACKUP_COPY_DIRECTORY));

        $this->asMember($this->assistant)
            ->put(self::DESTINATION_URL, ['copy_directory' => $this->copyFolder, 'copy_keep' => 5])
            ->assertForbidden();
    }

    public function test_a_listed_backup_is_downloaded_only_by_the_doctor_after_password_confirmation(): void
    {
        $this->fakeLocalBackups()->create();
        $archive = 'Drclick-Backup-local-1.msbackup';

        // Without a recent password confirmation, the password is asked first.
        $this->actingAs($this->doctor)
            ->withServerVariables($this->server())
            ->get(self::DOWNLOAD_URL.'?archive='.$archive)
            ->assertRedirect(route('password.confirm'));

        $this->asMember($this->doctor)
            ->get(self::DOWNLOAD_URL.'?archive='.$archive)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.medismart.backup')
            ->assertDownload($archive);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.local_downloaded']);

        // Nothing outside the managed folder is reachable.
        foreach (['../'.$archive, '..%2F..%2Fdatabase.sqlite', 'drive-outbox/'.$archive, 'missing.msbackup'] as $forged) {
            $this->asMember($this->doctor)->get(self::DOWNLOAD_URL.'?archive='.$forged)->assertNotFound();
        }

        $this->asMember($this->assistant)->get(self::DOWNLOAD_URL.'?archive='.$archive)->assertForbidden();
    }

    public function test_a_manual_backup_can_also_go_to_drive_with_a_one_off_passphrase(): void
    {
        $this->fakeLocalBackups();
        $passphrases = [];
        app()->instance(EncryptedAutomaticBackupCreator::class, new class($passphrases) implements EncryptedAutomaticBackupCreator
        {
            /** @param list<string> $passphrases */
            public function __construct(public array &$passphrases) {}

            public function create(#[SensitiveParameter] string $passphrase): BackupRecord
            {
                $this->passphrases[] = $passphrase;

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
        config([
            'services.google.client_id' => 'drclick-test.apps.googleusercontent.com',
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
        ]);
        DriveBackupConnection::query()->create([
            'cabinet_setting_id' => CabinetSetting::current($this->cabinet)->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        // Without the box ticked, the doctor is told how to send it.
        $this->asMember($this->doctor)
            ->post(self::BACKUP_NOW_URL)
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_contains($message, 'Envoyer aussi sur Google Drive'),
            );
        Queue::assertNothingPushed();

        $this->asMember($this->doctor)
            ->post(self::BACKUP_NOW_URL, [
                'send_to_drive' => true,
                'drive_passphrase' => 'court',
                'drive_passphrase_confirmation' => 'court',
            ])
            ->assertSessionHasErrors('drive_passphrase');

        $this->asMember($this->doctor)
            ->post(self::BACKUP_NOW_URL, [
                'send_to_drive' => true,
                'drive_passphrase' => 'une phrase secrète solide',
                'drive_passphrase_confirmation' => 'une phrase secrète solide',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => str_contains($message, 'part vers Google Drive'),
            );

        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
        $this->assertSame(['une phrase secrète solide'], $passphrases);
        // The one-off passphrase is never kept.
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE]);
    }

    public function test_the_reason_a_backup_did_not_reach_drive_is_shown_until_an_upload_succeeds(): void
    {
        ApplicationEvent::record('ScheduledDriveBackupSkipped', 'warning', context: [
            'reason' => 'drive_not_connected',
        ]);

        $this->asMember($this->doctor)
            ->get(self::SETTINGS_URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('driveAutomation.last_issue.reason', 'drive_not_connected')
                ->where('driveAutomation.last_issue.message', fn (string $message): bool => str_contains($message, 'reconnectez')));

        $this->travel(1)->minutes();
        ApplicationEvent::record('BackupDriveUploadCompleted');

        $this->asMember($this->doctor)
            ->get(self::SETTINGS_URL)
            ->assertInertia(fn (Assert $page) => $page->where('driveAutomation.last_issue', null));
    }

    private function fakeLocalBackups(): object
    {
        $creator = new class($this->managedRoot) implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function __construct(private readonly string $root) {}

            public function create(): BackupRecord
            {
                $this->calls++;
                $filename = 'Drclick-Backup-local-'.$this->calls.'.msbackup';
                $path = $this->root.DIRECTORY_SEPARATOR.$filename;
                $bytes = "PK\x03\x04local archive ".$this->calls;
                file_put_contents($path, $bytes);

                return BackupRecord::query()->create([
                    'filename' => $filename,
                    'disk' => 'local',
                    'local_path' => $path,
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
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
        $settings = CabinetSetting::current($this->cabinet);
        $user = User::factory()->create([
            'name' => $name,
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => $settings->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /** @return array<string, string|int> */
    private function server(): array
    {
        return [
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ];
    }

    private function asMember(User $user): self
    {
        return $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables($this->server());
    }
}
