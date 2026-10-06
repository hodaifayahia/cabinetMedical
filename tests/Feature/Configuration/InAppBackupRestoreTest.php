<?php

namespace Tests\Feature\Configuration;

use App\Backups\InAppBackupRestorer;
use App\Backups\MsBackupEncryptionParameters;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\Patient;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\BackupService;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use SQLite3;
use Tests\Support\RequiresSqlite;
use Tests\TestCase;
use ZipArchive;

/**
 * The clinic's doctor restores a backup over the running desktop from
 * Configuration › Sauvegardes: the archive is verified and summarised first,
 * a safety backup of the current data is written, the data is replaced, and
 * everybody signs in again.
 */
class InAppBackupRestoreTest extends TestCase
{
    use RequiresSqlite;

    private const ORIGIN = 'http://127.0.0.1:43123';

    private const PREPARE_URL = self::ORIGIN.'/app/configuration/backup/archives/restore/prepare';

    private const APPLY_URL = self::ORIGIN.'/app/configuration/backup/archives/restore/apply';

    private const PASSPHRASE = 'phrase secrète de la clinique';

    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $managedRoot;

    private string $privateRoot;

    private string $publicRoot;

    private Cabinet $cabinet;

    private User $doctor;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'drclick-in-app-restore-db-');

        if (! is_string($databaseFile)) {
            throw new \RuntimeException('The restore test database could not be created.');
        }

        self::$databaseFiles[] = $databaseFile;
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('database.connections.sqlite.database', $databaseFile);

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$databaseFiles as $databaseFile) {
            foreach ([$databaseFile, $databaseFile.'-wal', $databaseFile.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        self::$databaseFiles = [];
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class) || ! extension_loaded('sodium') || ! class_exists(SQLite3::class)) {
            $this->markTestSkipped('The ZIP, Sodium and SQLite3 extensions are required to restore a backup.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Mail::fake();
        Queue::fake();
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-in-app-restore-'.Str::uuid();
        $this->managedRoot = $this->workspace.DIRECTORY_SEPARATOR.'managed-backups';
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';

        foreach ([$this->managedRoot, $this->privateRoot, $this->publicRoot, $this->workspace.DIRECTORY_SEPARATOR.'usb'] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.version' => '2.1.0-restore-test',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6',
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cabinet = Cabinet::query()->create(['name' => 'Cabinet Lumière', 'status' => CabinetStatus::PENDING]);
        $settings = CabinetSetting::current($this->cabinet);
        $this->doctor = User::factory()->create([
            'email' => 'docteur@lumiere.test',
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => $settings->getKey(),
            'approved_at' => now(),
        ]);
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->cabinet->forceFill(['owner_user_id' => $this->doctor->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($this->cabinet, LicensePlan::LIFETIME);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        File::deleteDirectory(storage_path('app/private/restore-work'));

        parent::tearDown();
    }

    public function test_the_doctor_restores_an_encrypted_backup_after_seeing_what_it_holds(): void
    {
        Patient::factory()->create(['first_name' => 'Amina']);
        $this->document('patient-documents/scan.pdf', 'scan du jour J');
        $backup = $this->exportedBackup(encrypted: true);

        // Life goes on after the backup: a patient, a document, a deletion.
        Patient::factory()->create(['first_name' => 'Karim']);
        $this->document('patient-documents/apres.pdf', 'ajouté après');
        unlink($this->privateRoot.'/patient-documents/scan.pdf');
        // This PC's own settings must survive the restore.
        app(ApplicationSettingService::class)->set(Setting::BACKUP_COPY_DIRECTORY, $this->workspace.DIRECTORY_SEPARATOR.'usb');
        $this->assertSame(2, DB::table('patients')->count());

        $prepared = $this->asDoctor()
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::PREPARE_URL, $this->upload($backup, self::PASSPHRASE))
            ->assertOk()
            ->assertJsonPath('summary.cabinet', 'Cabinet Lumière')
            ->assertJsonPath('summary.patients', 1)
            ->assertJsonPath('summary.encrypted', true)
            ->assertJsonPath('confirmation', 'RESTAURER');
        $operationId = (string) $prepared->json('operation_id');

        // Seeing the summary changed nothing.
        $this->assertSame(2, DB::table('patients')->count());

        // Both confirmations are required.
        $this->asDoctor()
            ->postJson(self::APPLY_URL, ['operation_id' => $operationId, 'confirmed' => true, 'confirmation' => 'oui'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
        $this->asDoctor()
            ->postJson(self::APPLY_URL, ['operation_id' => $operationId, 'confirmed' => false, 'confirmation' => 'RESTAURER'])
            ->assertJsonValidationErrors('confirmed');
        $this->assertSame(2, DB::table('patients')->count());

        $this->asDoctor()
            ->postJson(self::APPLY_URL, ['operation_id' => $operationId, 'confirmed' => true, 'confirmation' => 'RESTAURER'])
            ->assertOk()
            ->assertJsonPath('redirect', route('login'))
            ->assertJson(fn ($json) => $json->where('message', fn (string $message): bool => str_contains($message, '« Cabinet Lumière »')
                && str_contains($message, '1 patient)'))->etc());

        // The data is the backup's again: patients and documents.
        $this->assertSame(['Amina'], DB::table('patients')->pluck('first_name')->all());
        $this->assertSame('scan du jour J', file_get_contents($this->privateRoot.'/patient-documents/scan.pdf'));
        $this->assertFileDoesNotExist($this->privateRoot.'/patient-documents/apres.pdf');
        $this->assertSame([], glob($this->privateRoot.'/.medismart-in-app-restore-*') ?: []);
        $this->assertSame($this->workspace.DIRECTORY_SEPARATOR.'usb', app(ApplicationSettingService::class)->get(Setting::BACKUP_COPY_DIRECTORY));

        // The replaced data is kept in a verified safety backup, listed on this PC.
        $safety = glob($this->managedRoot.'/'.InAppBackupRestorer::SAFETY_DIRECTORY.'/Drclick-Pre-Restore-Safety-*.msbackup') ?: [];
        $this->assertCount(1, $safety);
        $this->assertTrue(BackupRecord::query()->where('local_path', $safety[0])->where('status', 'completed')->exists());
        // The archive the restore came from is still listed too.
        $this->assertTrue(BackupRecord::query()->where('filename', basename($backup))->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restored']);
        $this->assertSame([], glob(storage_path('app/private/restore-work/in-app-*')) ?: []);

        // Everybody signs in again.
        $this->assertGuest('web');
    }

    public function test_a_backup_listed_on_this_pc_restores_without_uploading_it(): void
    {
        Patient::factory()->create(['first_name' => 'Amina']);
        $backup = $this->exportedBackup(encrypted: false);
        Patient::factory()->create(['first_name' => 'Karim']);

        $operationId = (string) $this->asDoctor()
            ->postJson(self::PREPARE_URL, ['archive' => basename($backup)])
            ->assertOk()
            ->assertJsonPath('summary.patients', 1)
            ->assertJsonPath('summary.encrypted', false)
            ->json('operation_id');

        $this->asDoctor()
            ->postJson(self::APPLY_URL, ['operation_id' => $operationId, 'confirmed' => true, 'confirmation' => 'RESTAURER'])
            ->assertOk();

        $this->assertSame(['Amina'], DB::table('patients')->pluck('first_name')->all());
    }

    public function test_a_wrong_passphrase_or_a_foreign_file_changes_nothing(): void
    {
        Patient::factory()->create();
        $backup = $this->exportedBackup(encrypted: true);
        Patient::factory()->create();

        $this->prepare($this->upload($backup, 'pas la bonne phrase'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passphrase' => 'Phrase secrète incorrecte']);
        $this->prepare($this->upload($backup, null))
            ->assertJsonValidationErrors(['passphrase' => 'Cette sauvegarde est chiffrée']);

        $notABackup = $this->workspace.DIRECTORY_SEPARATOR.'usb'.DIRECTORY_SEPARATOR.'photo.msbackup';
        file_put_contents($notABackup, random_bytes(2048));
        $this->prepare($this->upload($notABackup, null))
            ->assertJsonValidationErrors(['backup' => 'n’est pas une sauvegarde Drclick valide']);
        $this->prepare(['archive' => '../'.basename($backup)])
            ->assertJsonValidationErrors(['backup' => 'n’est plus présente']);

        // An operation that was never prepared in this session is refused.
        $this->asDoctor()
            ->postJson(self::APPLY_URL, ['operation_id' => (string) Str::uuid(), 'confirmed' => true, 'confirmation' => 'RESTAURER'])
            ->assertJsonValidationErrors('backup');

        $this->assertSame(2, DB::table('patients')->count());
        $this->assertSame([], glob($this->managedRoot.'/'.InAppBackupRestorer::SAFETY_DIRECTORY.'/*') ?: []);
        $this->assertSame([], glob(storage_path('app/private/restore-work/in-app-*')) ?: []);
    }

    public function test_only_the_doctor_of_the_supervised_desktop_may_restore(): void
    {
        $assistant = User::factory()->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($this->cabinet)->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->actingAs($assistant)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables($this->server())
            ->postJson(self::PREPARE_URL, ['archive' => 'Drclick-Backup-x.msbackup'])
            ->assertForbidden();

        // Off the desktop (no local SQLite file to replace) nothing can be restored here.
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->assertFalse(app(InAppBackupRestorer::class)->available());

        foreach (['app.configuration.backup.archives.restore.prepare', 'app.configuration.backup.archives.restore.apply'] as $name) {
            $this->assertContains('password.confirm', app('router')->getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    private function exportedBackup(bool $encrypted): string
    {
        $record = $encrypted
            ? app(BackupService::class)->createEncryptedArchive(
                self::PASSPHRASE,
                parameters: MsBackupEncryptionParameters::interactive(),
            )['record']
            : app(BackupService::class)->createArchive()['record'];

        return (string) $record->local_path;
    }

    private function document(string $relative, string $contents): void
    {
        $path = $this->privateRoot.DIRECTORY_SEPARATOR.$relative;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }

    /** @return array<string, mixed> */
    private function upload(string $path, ?string $passphrase): array
    {
        $copy = $this->workspace.DIRECTORY_SEPARATOR.'usb'.DIRECTORY_SEPARATOR.'upload-'.Str::random(6).'.msbackup';
        copy($path, $copy);

        return [
            'backup' => new UploadedFile($copy, 'sauvegarde.msbackup', null, null, true),
            'passphrase' => $passphrase,
        ];
    }

    /** @param array<string, mixed> $data */
    private function prepare(array $data): TestResponse
    {
        return $this->asDoctor()
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::PREPARE_URL, $data);
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

    private function asDoctor(): self
    {
        $doctor = User::query()->where('email', 'docteur@lumiere.test')->first() ?? $this->doctor;

        return $this->actingAs($doctor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables($this->server());
    }
}
