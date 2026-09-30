<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Backups\StagedSqliteValidator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Models\ApplicationSetting;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\Patient;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\BackupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use SQLite3;
use Tests\Support\RequiresSqlite;
use Tests\TestCase;
use ZipArchive;

/**
 * A new or reinstalled PC starts from a clinic backup before anyone has an
 * account on it. The backup comes from another PC, sealed with another
 * APP_KEY: the clinic's data and documents come back, this PC keeps its own
 * identity, and the other PC's secrets are dropped rather than left broken.
 */
class FirstRunBackupImportTest extends TestCase
{
    use RequiresSqlite;

    private const PASSPHRASE = 'recovery phrase for the new pc';

    private const SOURCE_INSTALLATION = '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6';

    private const NEW_INSTALLATION = '0c9e8d7f-6a5b-4c3d-9e2f-1a0b9c8d7e6f';

    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $managedRoot;

    private string $privateRoot;

    private string $publicRoot;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'drclick-first-run-db-');

        if (! is_string($databaseFile)) {
            throw new \RuntimeException('The first-run import test database could not be created.');
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
            $this->markTestSkipped('The ZIP, Sodium and SQLite3 extensions are required to import a backup.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-first-run-'.Str::uuid();
        $this->managedRoot = $this->workspace.DIRECTORY_SEPARATOR.'managed-backups';
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';

        foreach ([$this->managedRoot, $this->privateRoot, $this->publicRoot, $this->workspace.DIRECTORY_SEPARATOR.'transfer'] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        // The desktop only answers on its own loopback origin: relative
        // request URLs and route() must both use it.
        URL::forceRootUrl('http://127.0.0.1:43123');
        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.version' => '2.1.0-first-run-test',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.remote_upload_url' => null,
            'medismart.runtime.installation_id' => self::SOURCE_INSTALLATION,
        ]);
        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $resolvedWorkspace = realpath($this->workspace);

            if (is_string($temporaryRoot)
                && is_string($resolvedWorkspace)
                && str_starts_with($resolvedWorkspace, $temporaryRoot.DIRECTORY_SEPARATOR.'drclick-first-run-')) {
                File::deleteDirectory($resolvedWorkspace);
            }
        }

        parent::tearDown();
    }

    public function test_a_new_pc_starts_from_an_encrypted_backup_made_on_another_pc(): void
    {
        $archive = $this->backupOfASourceClinic(encrypted: true);
        $this->becomeAnotherPc();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canRestoreBackup', true));
        $this->get('/desktop/restore-backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/DesktopRestoreBackup'));

        // A wrong passphrase, or none, changes nothing on this PC.
        $this->from('/desktop/restore-backup')
            ->post('/desktop/restore-backup', $this->upload($archive, 'not the right phrase'))
            ->assertRedirect('/desktop/restore-backup')
            ->assertSessionHasErrors('passphrase');
        $this->post('/desktop/restore-backup', $this->upload($archive, null))
            ->assertSessionHasErrors('passphrase');
        $this->post('/desktop/restore-backup', [...$this->upload($archive, self::PASSPHRASE), 'confirmed' => '0'])
            ->assertSessionHasErrors('confirmed');
        $this->assertSame(0, User::query()->count());

        $this->post('/desktop/restore-backup', $this->upload($archive, self::PASSPHRASE))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '« Cabinet Source »')
                && str_contains($status, '1 patient)'));

        // The clinic is back: accounts (with their passwords), patients and
        // documents.
        $doctor = User::query()->where('email', 'docteur@source.test')->sole();
        $this->assertTrue(Hash::check('source-password', $doctor->password));
        $this->assertSame('Cabinet Source', Cabinet::query()->sole()->name);
        $this->assertSame(1, Patient::query()->count());
        $this->assertSame('scan', file_get_contents($this->privateRoot.'/patient-documents/scan.pdf'));
        $this->assertSame('logo', file_get_contents($this->publicRoot.'/cabinet/logo.png'));
        $this->assertSame(11, app(ApplicationSettingService::class)->get(Setting::BACKUP_RETENTION_DAILY));

        // This PC keeps its own machine settings…
        $this->assertSame(51000, app(ApplicationSettingService::class)->get(Setting::CONNECTIVITY_PREFERRED_PORT));
        // …and the other PC's sealed secrets are dropped, never left broken.
        $this->assertSame(0, DriveBackupConnection::query()->count());
        $this->assertNull(DB::table('users')->where('id', $doctor->getKey())->value('two_factor_secret'));
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE]);
        foreach (ApplicationSetting::query()->whereNotNull('encrypted_value')->get() as $setting) {
            $this->assertIsString(Crypt::decryptString((string) $setting->getRawOriginal('encrypted_value')));
        }
        // The other PC's archives are not on this one.
        $this->assertSame(0, BackupRecord::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.first_run_imported']);
        $this->assertSame([], glob(storage_path('app/private/restore-work/first-run-*')) ?: []);

        // Once the clinic is here, the page is gone.
        $this->get('/desktop/restore-backup')->assertRedirect(route('login'));
        $this->post('/desktop/restore-backup', $this->upload($archive, self::PASSPHRASE))
            ->assertRedirect(route('login'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canRestoreBackup', false));
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_plain_backup_restores_too_and_anything_else_is_refused(): void
    {
        $archive = $this->backupOfASourceClinic(encrypted: false);
        $this->becomeAnotherPc();

        $notABackup = $this->workspace.DIRECTORY_SEPARATOR.'transfer'.DIRECTORY_SEPARATOR.'photo.msbackup';
        file_put_contents($notABackup, random_bytes(2048));
        $this->post('/desktop/restore-backup', $this->upload($notABackup, null))
            ->assertSessionHasErrors(['backup' => 'Ce fichier n’est pas une sauvegarde Drclick valide, ou il est endommagé.']);
        $this->post('/desktop/restore-backup', [
            ...$this->upload($archive, null),
            'backup' => new UploadedFile($archive, 'sauvegarde.zip', null, null, true),
        ])->assertSessionHasErrors('backup');
        $this->assertSame(0, User::query()->count());

        $this->post('/desktop/restore-backup', $this->upload($archive, null))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame('docteur@source.test', User::query()->sole()->email);
    }

    public function test_the_page_is_closed_off_the_supervised_desktop(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->get('/desktop/restore-backup')->assertRedirect(route('login'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canRestoreBackup', false));
    }

    public function test_an_older_backup_is_accepted_for_import_but_not_one_from_a_newer_build(): void
    {
        $migrations = $this->workspace.DIRECTORY_SEPARATOR.'migrations';
        File::ensureDirectoryExists($migrations);

        foreach (['2026_01_01_000000_first', '2026_01_02_000000_second', '2026_01_03_000000_third'] as $name) {
            file_put_contents($migrations.DIRECTORY_SEPARATOR.$name.'.php', '<?php');
        }

        $validator = new StagedSqliteValidator($migrations);
        [$older, $olderManifest] = $this->stagedDatabase(['2026_01_01_000000_first', '2026_01_02_000000_second']);
        [$newer, $newerManifest] = $this->stagedDatabase(['2026_01_01_000000_first', '2026_01_04_000000_future']);

        $this->assertCount(2, $validator->validate($older, $olderManifest, allowOlderSchema: true));

        foreach ([[$older, $olderManifest, false], [$newer, $newerManifest, true], [$newer, $newerManifest, false]] as [$database, $manifest, $allowOlder]) {
            try {
                $validator->validate($database, $manifest, $allowOlder);
                $this->fail('An incompatible migration set was accepted.');
            } catch (BackupArchiveException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A clinic with an account, a patient, a document and sealed secrets, then its backup. */
    private function backupOfASourceClinic(bool $encrypted): string
    {
        $cabinet = Cabinet::query()->create(['name' => 'Cabinet Source', 'status' => CabinetStatus::ACTIVE]);
        $settings = CabinetSetting::current($cabinet);
        $doctor = User::factory()->create([
            'email' => 'docteur@source.test',
            'password' => Hash::make('source-password'),
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => $settings->getKey(),
            'approved_at' => now(),
        ]);
        $doctor->forceFill(['two_factor_secret' => encrypt('source-two-factor-secret')])->save();
        Patient::factory()->create();
        DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $settings->getKey(),
            'email' => 'cabinet@source.test',
            'folder_name' => 'Drclick Backups',
            'access_token' => 'source-access-token',
            'refresh_token' => 'source-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
        $settingsService = app(ApplicationSettingService::class);
        $settingsService->set(Setting::BACKUP_RETENTION_DAILY, 11);
        $settingsService->set(Setting::CONNECTIVITY_PREFERRED_PORT, 50000);
        $settingsService->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, 'source drive phrase 2026');
        File::ensureDirectoryExists($this->privateRoot.'/patient-documents');
        File::ensureDirectoryExists($this->publicRoot.'/cabinet');
        file_put_contents($this->privateRoot.'/patient-documents/scan.pdf', 'scan');
        file_put_contents($this->publicRoot.'/cabinet/logo.png', 'logo');

        $record = $encrypted
            ? app(BackupService::class)->createEncryptedArchive(
                self::PASSPHRASE,
                parameters: MsBackupEncryptionParameters::interactive(),
            )['record']
            : app(BackupService::class)->createArchive()['record'];
        $transfer = $this->workspace.DIRECTORY_SEPARATOR.'transfer'.DIRECTORY_SEPARATOR.$record->filename;
        $this->assertTrue(copy((string) $record->local_path, $transfer));

        return $transfer;
    }

    /** Same folders, but another PC: new APP_KEY, new identity, empty database and disk. */
    private function becomeAnotherPc(): void
    {
        File::cleanDirectory($this->managedRoot);
        File::cleanDirectory($this->privateRoot);
        File::cleanDirectory($this->publicRoot);
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'medismart.runtime.installation_id' => self::NEW_INSTALLATION,
        ]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        // A new installation: an empty database file, not a wiped one
        // (migrate:fresh truncates the file under its own WAL sidecar).
        $database = (string) DB::connection()->getDatabaseName();
        DB::purge();

        foreach ([$database.'-wal', $database.'-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }

        file_put_contents($database, '');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(ApplicationSettingService::class)->set(Setting::CONNECTIVITY_PREFERRED_PORT, 51000);
    }

    /** @return array<string, mixed> */
    private function upload(string $path, ?string $passphrase): array
    {
        return [
            'backup' => new UploadedFile($path, basename($path), null, null, true),
            'passphrase' => $passphrase,
            'confirmed' => '1',
        ];
    }

    /**
     * @param  list<string>  $migrations
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function stagedDatabase(array $migrations): array
    {
        $path = $this->workspace.DIRECTORY_SEPARATOR.Str::uuid().'.sqlite3';
        $database = new SQLite3($path);

        foreach (['users', 'patients', 'application_settings'] as $table) {
            $database->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }

        $database->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');

        foreach ($migrations as $migration) {
            $database->exec("INSERT INTO migrations (migration, batch) VALUES ('{$migration}', 1)");
        }

        $database->close();
        sort($migrations);

        return [$path, [
            'schema_version' => MsBackupArchiveCreator::DATABASE_SCHEMA_VERSION,
            'migration_count' => count($migrations),
            'latest_migration' => max($migrations),
            'migration_set_sha256' => hash('sha256', implode("\n", $migrations)),
        ]];
    }
}
