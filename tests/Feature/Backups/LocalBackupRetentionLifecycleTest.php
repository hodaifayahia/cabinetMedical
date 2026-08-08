<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\BackupRetentionPlanner;
use App\Backups\BackupRetentionPolicy;
use App\Backups\LocalBackupArchiveInspector;
use App\Backups\LocalBackupRetentionConfirmation;
use App\Backups\LocalBackupRetentionManager;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use App\Services\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class LocalBackupRetentionLifecycleTest extends TestCase
{
    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $managedRoot;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-retention-db-');

        if (! is_string($databaseFile)) {
            throw new \RuntimeException('The retention test database could not be created.');
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

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required for retention lifecycle tests.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'medismart-retention-'.Str::uuid();
        $this->managedRoot = $this->workspace.DIRECTORY_SEPARATOR.'managed-backups';
        $privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';

        File::ensureDirectoryExists($this->managedRoot);
        File::ensureDirectoryExists($privateRoot.DIRECTORY_SEPARATOR.'patient-documents');
        File::ensureDirectoryExists($publicRoot.DIRECTORY_SEPARATOR.'cabinet');
        file_put_contents($privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'fixture.txt', 'fixture');
        file_put_contents($publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.txt', 'logo');

        config([
            'filesystems.disks.local.root' => $privateRoot,
            'filesystems.disks.public.root' => $publicRoot,
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.version' => '2.1.0-retention-test',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        if (isset($this->workspace)) {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $resolvedWorkspace = realpath($this->workspace);

            if (is_string($temporaryRoot)
                && is_string($resolvedWorkspace)
                && str_starts_with(
                    $resolvedWorkspace,
                    $temporaryRoot.DIRECTORY_SEPARATOR.'medismart-retention-',
                )) {
                File::deleteDirectory($resolvedWorkspace);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_selects_only_verified_v1_records_and_protects_every_other_entry(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');
        $safetyDirectory = $this->managedRoot.DIRECTORY_SEPARATOR.'pre-restore-safety';
        File::ensureDirectoryExists($safetyDirectory);
        $safetyPath = $safetyDirectory.DIRECTORY_SEPARATOR.'Drclick-Pre-Restore-Safety-copy.msbackup';
        copy($new->local_path, $safetyPath);
        $orphanPath = $this->managedRoot.DIRECTORY_SEPARATOR.'malformed-orphan.msbackup';
        file_put_contents($orphanPath, 'not-an-archive');
        $linkPath = $this->managedRoot.DIRECTORY_SEPARATOR.'unmanaged-link.msbackup';
        symlink($new->local_path, $linkPath);
        $missing = $this->createMissingRecord('Drclick-Backup-missing.msbackup');

        $preview = $this->manager()->preview(new BackupRetentionPolicy(0, 0, 0));
        $array = $preview->toArray();
        $reasons = array_column($array['protected_entries'], 'reason_code');

        $this->assertSame([$new->id], array_column($array['plan']['keep'], 'managed_file_id'));
        $this->assertSame([$old->id], array_column($array['plan']['deletion_candidates'], 'managed_file_id'));
        $this->assertContains('pre_restore_safety_archive', $reasons);
        $this->assertContains('unowned_managed_directory_file', $reasons);
        $this->assertContains('unmanaged_symbolic_link', $reasons);
        $this->assertContains('managed_backup_file_missing', $reasons);
        $this->assertSame('completed', $missing->fresh()->status);
        $this->assertFileExists($old->local_path);
        $this->assertFileExists($new->local_path);
        $this->assertFileExists($safetyPath);
        $this->assertFileExists($orphanPath);
        $this->assertTrue(is_link($linkPath));
        $this->assertFalse($array['destructive_actions_performed']);
        $this->assertStringNotContainsString($this->managedRoot, json_encode($array, JSON_THROW_ON_ERROR));
    }

    public function test_conflicting_rows_for_one_physical_archive_are_protected_together(): void
    {
        $record = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-conflict.msbackup');
        $duplicate = $record->replicate();
        $duplicate->id = (string) Str::uuid();
        $duplicate->local_path = $this->managedRoot.DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.$record->filename;
        $duplicate->save();

        $preview = $this->manager()->preview(new BackupRetentionPolicy(0, 0, 0));
        $protected = collect($preview->protectedEntries)
            ->where('reason_code', 'conflicting_backup_records');

        $this->assertSame([], $preview->plan->keep);
        $this->assertSame([], $preview->plan->deletionCandidates);
        $this->assertSame(3, $protected->count());
        $this->assertEqualsCanonicalizing(
            [$record->id, $duplicate->id, null],
            $protected->pluck('record_id')->all(),
        );
        $this->assertFileExists($record->local_path);
        $this->assertSame('completed', $record->fresh()->status);
        $this->assertSame('completed', $duplicate->fresh()->status);
    }

    public function test_hard_linked_archives_are_never_candidates_and_physical_bytes_are_counted_once(): void
    {
        $record = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-linked.msbackup');
        $alias = $this->managedRoot.DIRECTORY_SEPARATOR.'unowned-hardlink.msbackup';
        $this->assertTrue(link($record->local_path, $alias));

        $preview = $this->manager()->preview(new BackupRetentionPolicy(0, 0, 0));
        $reasons = array_column($preview->protectedEntries, 'reason_code');

        $this->assertSame([], $preview->plan->keep);
        $this->assertSame([], $preview->plan->deletionCandidates);
        $this->assertContains('managed_backup_file_not_unique_regular', $reasons);
        $this->assertSame($record->size, $preview->plan->summary['protected_storage_bytes']);
        $this->assertFileExists($record->local_path);
        $this->assertFileExists($alias);
        $this->assertSame('completed', $record->fresh()->status);
    }

    public function test_completed_encrypted_v2_records_share_the_same_safe_lifecycle(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('The Sodium extension is required for encrypted backup retention.');
        }

        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old-v1.msbackup');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01T10:00:00+00:00'));
        $workingDirectory = $this->workspace.DIRECTORY_SEPARATOR.'encrypted-work';
        File::ensureDirectoryExists($workingDirectory);
        $encrypted = app(BackupService::class)->createEncryptedArchive(
            'retention lifecycle recovery phrase 2026',
            destinationDirectory: $this->managedRoot,
            parameters: MsBackupEncryptionParameters::interactive(),
            workingDirectory: $workingDirectory,
        );
        CarbonImmutable::setTestNow();

        $preview = $this->manager()->preview(new BackupRetentionPolicy(0, 0, 0));

        $this->assertSame(
            [$encrypted['record']->id],
            array_column($preview->plan->keep, 'managed_file_id'),
        );
        $this->assertSame(2, $preview->plan->keep[0]['format_version']);
        $this->assertSame([$old->id], array_column($preview->plan->deletionCandidates, 'managed_file_id'));
        $this->assertFileExists($encrypted['path']);
        $this->assertSame('completed', $encrypted['record']->fresh()->status);
    }

    public function test_maximum_storage_trims_oldest_tier_keep_but_never_newest_or_safety_archive(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');
        $safetyDirectory = $this->managedRoot.DIRECTORY_SEPARATOR.'pre-restore-safety';
        File::ensureDirectoryExists($safetyDirectory);
        $safetyPath = $safetyDirectory.DIRECTORY_SEPARATOR.'Drclick-Pre-Restore-Safety-copy.msbackup';
        copy($old->local_path, $safetyPath);
        $safetySize = filesize($safetyPath);

        $this->assertIsInt($safetySize);
        $limit = $safetySize + $new->size;
        $preview = $this->manager()->preview(new BackupRetentionPolicy(365, 104, 120, $limit));
        $plan = $preview->plan->toArray();

        $this->assertSame([$new->id], array_column($plan['keep'], 'managed_file_id'));
        $this->assertSame([$old->id], array_column($plan['deletion_candidates'], 'managed_file_id'));
        $this->assertSame('maximum_storage_bytes', $plan['deletion_candidates'][0]['reason_code']);
        $this->assertTrue($plan['summary']['maximum_storage_satisfied']);
        $this->assertSame($limit, $plan['summary']['projected_storage_bytes']);

        $unsatisfied = $this->manager()->preview(new BackupRetentionPolicy(0, 0, 0, $limit - 1));
        $this->assertSame([$new->id], array_column($unsatisfied->plan->keep, 'managed_file_id'));
        $this->assertFalse($unsatisfied->plan->summary['maximum_storage_satisfied']);
        $this->assertFileExists($safetyPath);
    }

    public function test_apply_requires_both_internal_flag_and_a_fresh_matching_token(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');
        $manager = $this->manager();
        $policy = new BackupRetentionPolicy(0, 0, 0);
        $token = $manager->issueConfirmation($manager->preview($policy));

        try {
            $manager->apply($token, false, $policy);
            $this->fail('The internal confirmation flag must be mandatory.');
        } catch (BackupArchiveException) {
            $this->addToAssertionCount(1);
        }

        try {
            $manager->apply('', true, $policy);
            $this->fail('A missing confirmation token must be rejected.');
        } catch (BackupArchiveException) {
            $this->addToAssertionCount(1);
        }

        try {
            $manager->apply('invalid-token', true, $policy);
            $this->fail('An invalid confirmation token must be rejected.');
        } catch (BackupArchiveException) {
            $this->addToAssertionCount(1);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        try {
            $manager->apply($token, true, $policy);
            $this->fail('An expired confirmation token must be rejected.');
        } catch (BackupArchiveException) {
            $this->addToAssertionCount(1);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertFileExists($old->local_path);
        $this->assertFileExists($new->local_path);
        $this->assertSame('completed', $old->fresh()->status);
        $this->assertSame('completed', $new->fresh()->status);
    }

    public function test_inventory_mutation_after_token_fails_closed_without_deleting_anything(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');
        $manager = $this->manager();
        $policy = new BackupRetentionPolicy(0, 0, 0);
        $token = $manager->issueConfirmation($manager->preview($policy));
        file_put_contents($old->local_path, 'mutation', FILE_APPEND);

        try {
            $manager->apply($token, true, $policy);
            $this->fail('A stale inventory token must not authorize deletion.');
        } catch (BackupArchiveException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFileExists($old->local_path);
        $this->assertFileExists($new->local_path);
        $this->assertSame('completed', $old->fresh()->status);
        $this->assertSame('completed', $new->fresh()->status);
    }

    public function test_valid_apply_replans_each_old_candidate_and_preserves_database_history(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $oldPath = $old->local_path;
        $middle = $this->createArchive('2026-07-15T10:00:00+00:00', 'Drclick-Backup-middle.msbackup');
        $middlePath = $middle->local_path;
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');
        $manager = $this->manager();
        $policy = new BackupRetentionPolicy(0, 0, 0);
        $token = $manager->issueConfirmation($manager->preview($policy));

        $result = $manager->apply($token, true, $policy);

        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame([$old->id, $middle->id], $result['deleted_record_ids']);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileDoesNotExist($middlePath);
        $this->assertFileExists($new->local_path);
        $this->assertSame('retention_deleted', $old->fresh()->status);
        $this->assertNull($old->fresh()->local_path);
        $this->assertSame('retention_deleted', $middle->fresh()->status);
        $this->assertNull($middle->fresh()->local_path);
        $this->assertSame('completed', $new->fresh()->status);
        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'backup.retention_deleted')->count(),
        );
        $this->assertSame(
            2,
            ApplicationEvent::query()->where('event', 'BackupRetentionDeleted')->count(),
        );
    }

    public function test_command_defaults_to_dry_run_and_apply_without_internal_confirmation_fails(): void
    {
        $old = $this->createArchive('2026-07-01T10:00:00+00:00', 'Drclick-Backup-old.msbackup');
        $new = $this->createArchive('2026-08-01T10:00:00+00:00', 'Drclick-Backup-new.msbackup');

        $this->artisan('medismart:backup:retention')
            ->expectsOutputToContain('"mode": "dry_run"')
            ->assertSuccessful();
        $this->artisan('medismart:backup:retention', [
            '--apply' => true,
            '--confirm' => 'invalid-token',
        ])->assertFailed();

        $this->assertFileExists($old->local_path);
        $this->assertFileExists($new->local_path);
        $this->assertSame('completed', $old->fresh()->status);
        $this->assertSame('completed', $new->fresh()->status);
    }

    private function manager(): LocalBackupRetentionManager
    {
        return new LocalBackupRetentionManager(
            app(BackupRetentionPlanner::class),
            app(LocalBackupArchiveInspector::class),
            new LocalBackupRetentionConfirmation(str_repeat('retention-secret-', 3)),
            app(ApplicationSettingService::class),
            $this->managedRoot,
        );
    }

    private function createArchive(string $createdAt, string $filename): BackupRecord
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($createdAt));
        $created = app(MsBackupArchiveCreator::class)->create(
            $this->managedRoot,
            $filename,
            (string) Str::uuid(),
        );
        $record = BackupRecord::query()->create([
            'filename' => $created['filename'],
            'disk' => 'local',
            'local_path' => $created['path'],
            'size' => $created['size'],
            'sha256' => $created['sha256'],
            'schema_version' => $created['manifest']['schema_version'],
            'application_version' => $created['manifest']['application_version'],
            'status' => 'completed',
            'started_at' => CarbonImmutable::parse($createdAt),
            'completed_at' => CarbonImmutable::parse($createdAt),
        ]);
        CarbonImmutable::setTestNow();

        return $record->refresh();
    }

    private function createMissingRecord(string $filename): BackupRecord
    {
        return BackupRecord::query()->create([
            'filename' => $filename,
            'disk' => 'local',
            'local_path' => $this->managedRoot.DIRECTORY_SEPARATOR.$filename,
            'size' => 100,
            'sha256' => str_repeat('a', 64),
            'schema_version' => 1,
            'application_version' => '2.1.0-retention-test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
