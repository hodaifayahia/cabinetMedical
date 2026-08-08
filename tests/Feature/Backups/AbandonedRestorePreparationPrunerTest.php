<?php

namespace Tests\Feature\Backups;

use App\Backups\AbandonedRestorePreparationPruner;
use App\Backups\EncryptedMsBackupArchive;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Backups\OfflineRestorePreparer;
use App\Backups\PreparedRestore;
use App\Backups\RestoreLifecycleLease;
use App\Backups\RestoreLifecycleLock;
use App\Backups\RestoreRecoveryJournal;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

class AbandonedRestorePreparationPrunerTest extends TestCase
{
    private const PASSPHRASE = 'abandoned restore pruning phrase 2026';

    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $storageRoot;

    private string $privateRoot;

    private string $publicRoot;

    private string $destination;

    private CarbonImmutable $referenceNow;

    private ?string $encryptedPath = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-prune-db-');
        $storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'medismart-prune-storage-'.Str::uuid();

        if (! is_string($databaseFile) || ! mkdir($storageRoot, 0700, true)) {
            throw new RuntimeException('The restore pruning test roots could not be created.');
        }

        self::$databaseFiles[] = $databaseFile;
        $this->storageRoot = $storageRoot;
        $app->useStoragePath($storageRoot);
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
            $this->markTestSkipped('The Sodium and ZIP extensions are required for restore pruning tests.');
        }

        DB::purge('sqlite');
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->referenceNow = CarbonImmutable::parse('2026-08-05 12:00:00', config('app.timezone'));
        $this->travelTo($this->referenceNow);
        $this->privateRoot = storage_path('framework/testing/prune-assets/private');
        $this->publicRoot = storage_path('framework/testing/prune-assets/public');
        $this->destination = storage_path('framework/testing/prune-assets/backups');

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
            'prune-clinical-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            'prune-patient-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'medical-models'.DIRECTORY_SEPARATOR.'model.docx',
            'prune-medical-model',
        );
        file_put_contents(
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.png',
            'prune-clinic-logo',
        );

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.version' => '2.3.0-prune-test',
            'medismart.backups.prepared_restore_retention_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        if (isset($this->storageRoot)
            && str_starts_with(basename($this->storageRoot), 'medismart-prune-storage-')
            && dirname($this->storageRoot) === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($this->storageRoot);
        }

        parent::tearDown();
    }

    public function test_expired_intact_ready_pair_is_pruned_and_only_aggregate_data_is_audited(): void
    {
        $prepared = $this->oldPreparedRestore('expired');

        $exitCode = Artisan::call('medismart:restore:prune-preparations');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist($prepared->workspace);
        $this->assertFileDoesNotExist($prepared->journalPath);
        $this->assertFileExists(storage_path('app/private/restore-lifecycle.lock'));
        $audit = AuditLog::query()->where('action', 'restore.abandoned_preparations_pruned')->sole();
        $this->assertSame([
            'retention_hours' => 24,
            'lock_acquired' => true,
            'workspace_entries' => 1,
            'journal_entries' => 1,
            'matched_pairs' => 1,
            'eligible_pairs' => 1,
            'pruned_pairs' => 1,
            'protected_recent' => 0,
            'protected_state' => 0,
            'protected_mismatch' => 0,
            'protected_unsafe' => 0,
            'race_changed' => 0,
            'failures' => 0,
            'destructive_actions_performed' => true,
        ], $audit->metadata);
        $serialized = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($prepared->operationId, $serialized);
        $this->assertStringNotContainsString($prepared->workspace, $serialized);
        $this->assertStringNotContainsString($prepared->planSha256, $serialized);
        $this->assertStringNotContainsString(self::PASSPHRASE, $serialized);
        $this->assertStringNotContainsString($prepared->operationId, $output);
        $this->assertStringNotContainsString($prepared->workspace, $output);
        $this->assertStringNotContainsString(self::PASSPHRASE, $output);
    }

    public function test_recently_prepared_or_recently_touched_pairs_are_never_pruned(): void
    {
        $recent = $this->preparedRestore('recent', $this->referenceNow);
        $touched = $this->oldPreparedRestore('recently-touched');
        $touchedFile = $touched->stagedManagedRoots()['patient_documents']
            .DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf';
        touch($touchedFile, $this->referenceNow->getTimestamp());

        $report = app(AbandonedRestorePreparationPruner::class)->prune();

        $this->assertTrue($report->lockAcquired);
        $this->assertSame(0, $report->prunedPairs);
        $this->assertSame(2, $report->protectedRecent);
        $this->assertDirectoryExists($recent->workspace);
        $this->assertFileExists($recent->journalPath);
        $this->assertDirectoryExists($touched->workspace);
        $this->assertFileExists($touched->journalPath);
    }

    public function test_in_progress_rollback_recovery_and_nonterminal_states_are_protected(): void
    {
        $applyStarted = $this->oldPreparedRestore('apply-started');
        $rollback = $this->oldPreparedRestore('rollback-completed');
        $manual = $this->oldPreparedRestore('manual-recovery');
        $nonterminalId = $this->oldNonterminalPair();
        $this->appendOldState($applyStarted, 'apply_started');
        $this->appendOldState($rollback, 'rollback_completed');
        $this->appendOldState($manual, 'manual_recovery_required');

        $report = app(AbandonedRestorePreparationPruner::class)->prune();

        $this->assertSame(0, $report->prunedPairs);
        $this->assertSame(4, $report->protectedState);

        foreach ([$applyStarted, $rollback, $manual] as $prepared) {
            $this->assertDirectoryExists($prepared->workspace);
            $this->assertFileExists($prepared->journalPath);
        }

        $this->assertDirectoryExists(storage_path('app/private/restore-work/'.$nonterminalId));
        $this->assertFileExists(storage_path('app/private/restore-journals/'.$nonterminalId.'.jsonl'));
    }

    public function test_symlinked_tampered_and_mismatched_entries_are_retained(): void
    {
        $symlinked = $this->oldPreparedRestore('symlinked');
        $tampered = $this->oldPreparedRestore('tampered');
        $outside = storage_path('framework/testing/prune-outside');
        File::ensureDirectoryExists($outside);
        $sentinel = $outside.DIRECTORY_SEPARATOR.'sentinel.txt';
        file_put_contents($sentinel, 'must-survive');
        $this->assertTrue(symlink(
            $sentinel,
            $symlinked->workspace.DIRECTORY_SEPARATOR.'staged'.DIRECTORY_SEPARATOR.'linked-sentinel',
        ));
        file_put_contents($tampered->workspace.DIRECTORY_SEPARATOR.'restore-plan.json', "{}\n");
        $orphanWorkspace = (string) Str::uuid();
        File::ensureDirectoryExists(storage_path('app/private/restore-work/'.$orphanWorkspace));
        $orphanJournal = (string) Str::uuid();
        RestoreRecoveryJournal::create($orphanJournal);
        $this->ageOperation($orphanWorkspace);
        $this->ageJournal($orphanJournal);

        $report = app(AbandonedRestorePreparationPruner::class)->prune();

        $this->assertSame(0, $report->prunedPairs);
        $this->assertGreaterThanOrEqual(1, $report->protectedUnsafe);
        $this->assertGreaterThanOrEqual(3, $report->protectedMismatch);
        $this->assertFileExists($sentinel);
        $this->assertSame('must-survive', file_get_contents($sentinel));
        $this->assertTrue(is_link(
            $symlinked->workspace.DIRECTORY_SEPARATOR.'staged'.DIRECTORY_SEPARATOR.'linked-sentinel',
        ));
        $this->assertDirectoryExists($tampered->workspace);
        $this->assertFileExists($tampered->journalPath);
        $this->assertDirectoryExists(storage_path('app/private/restore-work/'.$orphanWorkspace));
        $this->assertFileExists(storage_path('app/private/restore-journals/'.$orphanJournal.'.jsonl'));
    }

    public function test_busy_lock_and_state_change_races_skip_without_deletion(): void
    {
        $prepared = $this->oldPreparedRestore('race');
        $lease = app(RestoreLifecycleLock::class)->tryAcquire();
        $this->assertInstanceOf(RestoreLifecycleLease::class, $lease);

        try {
            $busy = app(AbandonedRestorePreparationPruner::class)->prune();
        } finally {
            $lease->release();
        }

        $this->assertFalse($busy->lockAcquired);
        $this->assertSame(0, $busy->prunedPairs);
        $this->assertDirectoryExists($prepared->workspace);
        $this->assertFileExists($prepared->journalPath);

        $raced = app(AbandonedRestorePreparationPruner::class)->prune(
            function (string $operationId): void {
                RestoreRecoveryJournal::open($operationId)->append('apply_started', [
                    'target_count' => 5,
                    'rollback_retained' => true,
                ]);
            },
        );

        $this->assertSame(0, $raced->prunedPairs);
        $this->assertSame(1, $raced->raceChanged);
        $this->assertDirectoryExists($prepared->workspace);
        $this->assertFileExists($prepared->journalPath);
    }

    public function test_invalid_retention_and_symlinked_managed_root_fail_closed(): void
    {
        $prepared = $this->oldPreparedRestore('invalid-retention');
        config(['medismart.backups.prepared_restore_retention_hours' => 23]);

        $this->assertSame(1, Artisan::call('medismart:restore:prune-preparations'));
        $this->assertDirectoryExists($prepared->workspace);
        $this->assertFileExists($prepared->journalPath);
        $failure = AuditLog::query()
            ->where('action', 'restore.abandoned_preparations_prune_failed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame([
            'reason_code' => 'configuration_or_managed_root_invalid',
            'destructive_actions_performed' => false,
        ], $failure->metadata);
        $this->assertStringNotContainsString($prepared->operationId, Artisan::output());

        config(['medismart.backups.prepared_restore_retention_hours' => 24]);
        File::deleteDirectory(storage_path('app/private/restore-work'));
        $outside = storage_path('framework/testing/root-symlink-target');
        File::ensureDirectoryExists($outside);
        $sentinel = $outside.DIRECTORY_SEPARATOR.'sentinel.txt';
        file_put_contents($sentinel, 'outside-root');
        $this->assertTrue(symlink($outside, storage_path('app/private/restore-work')));

        $this->assertSame(1, Artisan::call('medismart:restore:prune-preparations'));
        $this->assertFileExists($sentinel);
        $this->assertSame('outside-root', file_get_contents($sentinel));
    }

    public function test_daily_scheduler_registration_uses_overlap_protection(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(
            static fn ($candidate): bool => str_contains(
                (string) $candidate->command,
                'medismart:restore:prune-preparations',
            ),
        );

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function oldPreparedRestore(string $suffix): PreparedRestore
    {
        return $this->preparedRestore($suffix, $this->referenceNow->subHours(25));
    }

    private function preparedRestore(string $suffix, CarbonImmutable $preparedAt): PreparedRestore
    {
        $this->travelTo($preparedAt);
        $operationId = (string) Str::uuid();

        try {
            $prepared = app(OfflineRestorePreparer::class)->prepare(
                $this->encryptedBackup(),
                self::PASSPHRASE,
                $operationId,
            );
        } finally {
            $this->travelTo($this->referenceNow);
        }

        if ($preparedAt->lessThan($this->referenceNow->subHours(24))) {
            $this->ageOperation($operationId);
            $this->ageJournal($operationId);
        }

        return $prepared;
    }

    private function oldNonterminalPair(): string
    {
        $operationId = (string) Str::uuid();
        $this->travelTo($this->referenceNow->subHours(25));

        try {
            File::ensureDirectoryExists(storage_path('app/private/restore-work/'.$operationId));
            RestoreRecoveryJournal::create($operationId);
        } finally {
            $this->travelTo($this->referenceNow);
        }

        $this->ageOperation($operationId);
        $this->ageJournal($operationId);

        return $operationId;
    }

    private function appendOldState(PreparedRestore $prepared, string $event): void
    {
        $this->travelTo($this->referenceNow->subHours(25));

        try {
            RestoreRecoveryJournal::open($prepared->operationId)->append($event);
        } finally {
            $this->travelTo($this->referenceNow);
        }

        $this->ageOperation($prepared->operationId);
        $this->ageJournal($prepared->operationId);
    }

    private function encryptedBackup(): string
    {
        if (is_string($this->encryptedPath)) {
            return $this->encryptedPath;
        }

        $plain = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            'Drclick-Backup-prune-plain.msbackup',
        );
        $this->encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'Drclick-Backup-prune-encrypted.msbackup';
        app(EncryptedMsBackupArchive::class)->encrypt(
            $plain['path'],
            $this->encryptedPath,
            self::PASSPHRASE,
            new MsBackupEncryptionParameters(
                MsBackupEncryptionParameters::MINIMUM_OPERATIONS_LIMIT,
                MsBackupEncryptionParameters::MINIMUM_MEMORY_LIMIT_BYTES,
                MsBackupEncryptionParameters::MINIMUM_CHUNK_BYTES,
            ),
        );

        return $this->encryptedPath;
    }

    private function ageOperation(string $operationId): void
    {
        $workspace = storage_path('app/private/restore-work/'.$operationId);

        if (! is_dir($workspace)) {
            return;
        }

        $timestamp = $this->referenceNow->subHours(25)->getTimestamp();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry->isLink()) {
                touch($entry->getPathname(), $timestamp);
            }
        }

        touch($workspace, $timestamp);
    }

    private function ageJournal(string $operationId): void
    {
        $journal = storage_path('app/private/restore-journals/'.$operationId.'.jsonl');

        if (is_file($journal)) {
            touch($journal, $this->referenceNow->subHours(25)->getTimestamp());
        }
    }
}
