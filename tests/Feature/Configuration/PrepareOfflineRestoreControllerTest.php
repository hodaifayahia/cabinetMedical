<?php

namespace Tests\Feature\Configuration;

use App\Backups\EncryptedMsBackupArchive;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Backups\PreparedRestore;
use App\Backups\RestoreRecoveryJournal;
use App\Configuration\ApplicationSettingRegistry;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApplicationSettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class PrepareOfflineRestoreControllerTest extends TestCase
{
    private const PASSPHRASE = 'offline restore HTTP recovery phrase 2026';

    private const FAILURE_MESSAGE = 'La sauvegarde n\'a pas pu être authentifiée ou préparée.';

    /** @var list<string> */
    private static array $databaseFiles = [];

    /** @var list<string> */
    private array $initialWorkspaces = [];

    /** @var list<string> */
    private array $initialJournals = [];

    private string $workspace;

    private string $privateRoot;

    private string $publicRoot;

    private string $destination;

    private User $administrator;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-http-restore-db-');

        if (! is_string($databaseFile)) {
            throw new RuntimeException('The HTTP restore test database could not be created.');
        }

        self::$databaseFiles[] = $databaseFile;
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('database.connections.sqlite.database', $databaseFile);

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$databaseFiles as $databaseFile) {
            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }

        self::$databaseFiles = [];
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('sodium') || ! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The Sodium and ZIP extensions are required for HTTP restore tests.');
        }

        $this->initialWorkspaces = $this->entryNames(storage_path('app/private/restore-work'));
        $this->initialJournals = $this->entryNames(storage_path('app/private/restore-journals'));
        DB::purge('sqlite');

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $this->actingAs($this->administrator);
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->workspace = storage_path('framework/testing/http-restore-'.Str::uuid());
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';
        $this->destination = $this->workspace.DIRECTORY_SEPARATOR.'backups';

        foreach ([
            $this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1',
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1',
            $this->privateRoot.DIRECTORY_SEPARATOR.'medical-models',
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet',
            $this->destination,
        ] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'letter.pdf',
            'active-clinical-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            'active-patient-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'medical-models'.DIRECTORY_SEPARATOR.'model.docx',
            'active-medical-model',
        );
        file_put_contents(
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.png',
            'active-clinic-logo',
        );

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.version' => '2.3.0-http-restore-test',
            'medismart.backups.legacy_restore_enabled' => false,
            'medismart.backups.restore_upload_max_bytes' => 25 * 1024 * 1024 * 1024,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeNewRestoreArtifacts();

        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_administrator_can_prepare_an_encrypted_backup_without_applying_it(): void
    {
        $backup = $this->createEncryptedBackup('success');
        $marker = $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf';
        $activeHash = hash_file('sha256', $marker);

        $response = $this->prepare($backup['encrypted_path']);

        $response->assertOk();
        $this->assertFalse((bool) config('medismart.backups.legacy_restore_enabled'));
        $this->assertNoStoreHeaders($response);
        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertSame(['authorization', 'backup'], array_keys($payload));

        $authorization = $payload['authorization'];
        $summary = $payload['backup'];
        $this->assertIsArray($authorization);
        $this->assertIsArray($summary);
        $this->assertSame(
            ['protocol', 'version', 'operation_id', 'plan_sha256'],
            array_keys($authorization),
        );
        $this->assertSame(PreparedRestore::NATIVE_AUTHORIZATION_PROTOCOL, $authorization['protocol']);
        $this->assertSame(PreparedRestore::NATIVE_AUTHORIZATION_VERSION, $authorization['version']);
        $this->assertIsString($authorization['operation_id']);
        $this->assertTrue(Str::isUuid($authorization['operation_id']));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $authorization['plan_sha256']);

        $prepared = PreparedRestore::load($authorization['operation_id']);
        $this->assertSame($authorization['plan_sha256'], $prepared->planSha256);
        $this->assertSame(
            ['created_at', 'application_version', 'schema_version', 'components', 'file_count', 'size_bytes'],
            array_keys($summary),
        );
        $this->assertSame($backup['manifest']['created_at'], $summary['created_at']);
        $this->assertSame($backup['manifest']['application_version'], $summary['application_version']);
        $this->assertSame($backup['manifest']['schema_version'], $summary['schema_version']);
        $this->assertSame($prepared->stagedFileCount, $summary['file_count']);
        $this->assertSame($prepared->stagedBytes, $summary['size_bytes']);
        $this->assertSame(
            ['database', 'private_storage', 'public_storage'],
            array_column($summary['components'], 'name'),
        );

        foreach ($summary['components'] as $component) {
            $this->assertSame(['name', 'file_count', 'size_bytes'], array_keys($component));
        }

        $this->assertNoSensitiveSummaryKeys($summary);
        $content = $response->getContent();
        $this->assertStringNotContainsString(self::PASSPHRASE, $content);
        $this->assertStringNotContainsString($backup['encrypted_path'], $content);
        $this->assertStringNotContainsString($prepared->workspace, $content);
        $this->assertSame($activeHash, hash_file('sha256', $marker));
        $this->assertSame('ready_for_offline_apply', array_column(
            RestoreRecoveryJournal::open($prepared->operationId)->records(),
            'event',
        )[4]);

        $audit = AuditLog::query()->where('action', 'restore.offline_prepared')->sole();
        $this->assertSame($this->administrator->getKey(), $audit->user_id);
        $this->assertSame([
            'schema_version' => $summary['schema_version'],
            'application_version' => $summary['application_version'],
            'components' => ['database', 'private_storage', 'public_storage'],
            'file_count' => $summary['file_count'],
            'size_bytes' => $summary['size_bytes'],
            'active_data_changed' => false,
        ], $audit->metadata);
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PASSPHRASE, $auditJson);
        $this->assertStringNotContainsString($authorization['operation_id'], $auditJson);
        $this->assertStringNotContainsString($authorization['plan_sha256'], $auditJson);
        $this->assertStringNotContainsString($backup['encrypted_path'], $auditJson);
    }

    public function test_wrong_passphrase_returns_only_the_generic_failure(): void
    {
        $backup = $this->createEncryptedBackup('wrong-passphrase');
        $activeHash = $this->activeMarkerHash();

        $response = $this->prepare(
            $backup['encrypted_path'],
            'incorrect restore recovery phrase 2026',
        );

        $this->assertGenericFailure($response, [
            self::PASSPHRASE,
            'incorrect restore recovery phrase 2026',
            $backup['encrypted_path'],
            'sodium',
            'decrypt',
        ]);
        $this->assertSame($activeHash, $this->activeMarkerHash());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_malformed_and_installation_mismatched_archives_use_the_same_failure(): void
    {
        $backup = $this->createEncryptedBackup('archive-mismatch');

        $malformed = UploadedFile::fake()->createWithContent(
            'Drclick-Restore-malformed.msbackup',
            'not-an-encrypted-backup',
        );
        $this->assertGenericFailure($this->postPreparation($malformed, self::PASSPHRASE), [
            'not-an-encrypted-backup',
            'envelope',
            'archive',
        ]);

        app(ApplicationSettingService::class)->setInternal(
            ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID,
            (string) Str::uuid(),
        );
        $mismatch = $this->prepare($backup['encrypted_path']);
        $this->assertGenericFailure($mismatch, [
            self::PASSPHRASE,
            $backup['encrypted_path'],
            'installation',
            'another',
        ]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_only_safe_msbackup_filenames_are_accepted(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'Drclick-Restore.zip',
            'not-relevant',
        );

        $response = $this->postPreparation($upload, self::PASSPHRASE);

        $this->assertGenericFailure($response, ['Drclick-Restore.zip', self::PASSPHRASE]);

        $unsafeFixture = UploadedFile::fake()->createWithContent(
            'unsafe-fixture.msbackup',
            'not-relevant',
        );
        $unsafePath = new UploadedFile(
            $unsafeFixture->getPathname(),
            '../Drclick-Restore.msbackup',
            'application/octet-stream',
            UPLOAD_ERR_OK,
            true,
        );
        $this->assertGenericFailure(
            $this->postPreparation($unsafePath, self::PASSPHRASE),
            ['../Drclick-Restore.msbackup', self::PASSPHRASE],
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_configured_upload_size_limit_is_enforced(): void
    {
        $backup = $this->createEncryptedBackup('size-limit');
        $size = filesize($backup['encrypted_path']);
        $this->assertIsInt($size);
        $this->assertGreaterThan(1, $size);
        config(['medismart.backups.restore_upload_max_bytes' => $size - 1]);

        $response = $this->prepare($backup['encrypted_path']);

        $this->assertGenericFailure($response, [self::PASSPHRASE, $backup['encrypted_path']]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_expensive_restore_preparation_attempts_are_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->postPreparation(
                UploadedFile::fake()->createWithContent(
                    "Drclick-Restore-malformed-{$attempt}.msbackup",
                    'not-an-encrypted-backup',
                ),
                self::PASSPHRASE,
            );

            $response->assertUnprocessable();
        }

        $this->postPreparation(
            UploadedFile::fake()->createWithContent(
                'Drclick-Restore-malformed-blocked.msbackup',
                'not-an-encrypted-backup',
            ),
            self::PASSPHRASE,
        )
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_paths_unknown_fields_and_invalid_passphrases_are_rejected_without_flashing_secrets(): void
    {
        $backup = $this->createEncryptedBackup('request-boundary');

        $pathResponse = $this->withHeader('Accept', 'application/json')->post(
            route('app.configuration.backup.restore.prepare'),
            [
                'backup' => $backup['encrypted_path'],
                'passphrase' => self::PASSPHRASE,
            ],
        );
        $this->assertGenericFailure($pathResponse, [self::PASSPHRASE, $backup['encrypted_path']]);

        $unknownResponse = $this->postPreparation(
            $this->upload($backup['encrypted_path']),
            self::PASSPHRASE,
            ['operation_id' => (string) Str::uuid(), 'path' => $backup['encrypted_path']],
        );
        $this->assertGenericFailure($unknownResponse, [self::PASSPHRASE, $backup['encrypted_path']]);

        $invalidPassphrase = "short\0secret";
        $secretResponse = $this->postPreparation(
            $this->upload($backup['encrypted_path']),
            $invalidPassphrase,
        );
        $this->assertGenericFailure($secretResponse, ['short', 'secret', $backup['encrypted_path']]);
        $this->assertNull(session()->getOldInput('passphrase'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'restore.offline_prepared']);
        $this->assertSame([], $this->newWorkspaceNames());
    }

    public function test_replaying_the_same_authenticated_archive_creates_distinct_preparations(): void
    {
        $backup = $this->createEncryptedBackup('replay');

        $first = $this->prepare($backup['encrypted_path']);
        $second = $this->prepare($backup['encrypted_path']);

        $first->assertOk();
        $second->assertOk();
        $firstAuthorization = $first->json('authorization');
        $secondAuthorization = $second->json('authorization');
        $this->assertIsArray($firstAuthorization);
        $this->assertIsArray($secondAuthorization);
        $this->assertNotSame($firstAuthorization['operation_id'], $secondAuthorization['operation_id']);
        $this->assertNotSame($firstAuthorization['plan_sha256'], $secondAuthorization['plan_sha256']);
        $this->assertSame(
            $firstAuthorization['plan_sha256'],
            PreparedRestore::load($firstAuthorization['operation_id'])->planSha256,
        );
        $this->assertSame(
            $secondAuthorization['plan_sha256'],
            PreparedRestore::load($secondAuthorization['operation_id'])->planSha256,
        );
        $this->assertSame(2, AuditLog::query()->where('action', 'restore.offline_prepared')->count());
    }

    /** @return array{encrypted_path: string, manifest: array<string, mixed>} */
    private function createEncryptedBackup(string $suffix): array
    {
        $plain = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            "Drclick-Backup-{$suffix}-plain.msbackup",
        );
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR."Drclick-Backup-{$suffix}-encrypted.msbackup";
        app(EncryptedMsBackupArchive::class)->encrypt(
            $plain['path'],
            $encryptedPath,
            self::PASSPHRASE,
            new MsBackupEncryptionParameters(
                MsBackupEncryptionParameters::MINIMUM_OPERATIONS_LIMIT,
                MsBackupEncryptionParameters::MINIMUM_MEMORY_LIMIT_BYTES,
                MsBackupEncryptionParameters::MINIMUM_CHUNK_BYTES,
            ),
        );

        return [
            'encrypted_path' => $encryptedPath,
            'manifest' => $plain['manifest'],
        ];
    }

    private function prepare(
        string $archivePath,
        string $passphrase = self::PASSPHRASE,
    ): TestResponse {
        return $this->postPreparation($this->upload($archivePath), $passphrase);
    }

    /** @param array<string, mixed> $extra */
    private function postPreparation(
        UploadedFile $upload,
        string $passphrase,
        array $extra = [],
    ): TestResponse {
        return $this->withHeader('Accept', 'application/json')->post(
            route('app.configuration.backup.restore.prepare'),
            [
                'backup' => $upload,
                'passphrase' => $passphrase,
                ...$extra,
            ],
        );
    }

    private function upload(string $path): UploadedFile
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('The encrypted restore fixture could not be read.');
        }

        return UploadedFile::fake()->createWithContent(
            'Drclick-Restore-upload.msbackup',
            $contents,
        );
    }

    /** @param list<string> $forbidden */
    private function assertGenericFailure(TestResponse $response, array $forbidden): void
    {
        $response->assertStatus(422)->assertExactJson([
            'message' => self::FAILURE_MESSAGE,
        ]);
        $this->assertNoStoreHeaders($response);

        foreach ($forbidden as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    private function assertNoStoreHeaders(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string, mixed> $summary */
    private function assertNoSensitiveSummaryKeys(array $summary): void
    {
        foreach ($summary as $key => $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?:path|sha|hash|pass|secret|token|credential)/i',
                (string) $key,
            );

            if (is_array($value)) {
                $this->assertNoSensitiveSummaryKeys($value);
            }
        }
    }

    private function activeMarkerHash(): string
    {
        $hash = hash_file(
            'sha256',
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
        );

        if (! is_string($hash)) {
            throw new RuntimeException('The active restore marker could not be hashed.');
        }

        return $hash;
    }

    /** @return list<string> */
    private function newWorkspaceNames(): array
    {
        return array_values(array_diff(
            $this->entryNames(storage_path('app/private/restore-work')),
            $this->initialWorkspaces,
        ));
    }

    /** @return list<string> */
    private function entryNames(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if (! is_array($entries)) {
            return [];
        }

        $names = array_values(array_diff($entries, ['.', '..']));
        sort($names);

        return $names;
    }

    private function removeNewRestoreArtifacts(): void
    {
        $workRoot = storage_path('app/private/restore-work');

        foreach ($this->newWorkspaceNames() as $operationId) {
            if (Str::isUuid($operationId)) {
                File::deleteDirectory($workRoot.DIRECTORY_SEPARATOR.$operationId);
            }
        }

        $journalRoot = storage_path('app/private/restore-journals');
        $newJournals = array_diff($this->entryNames($journalRoot), $this->initialJournals);

        foreach ($newJournals as $filename) {
            if (preg_match('/\A[0-9a-f-]{36}\.jsonl\z/i', $filename) === 1) {
                File::delete($journalRoot.DIRECTORY_SEPARATOR.$filename);
            }
        }
    }
}
