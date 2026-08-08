<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

final class LocalBackupRetentionManager
{
    public function __construct(
        private readonly BackupRetentionPlanner $planner,
        private readonly LocalBackupArchiveInspector $inspector,
        private readonly LocalBackupRetentionConfirmation $confirmation,
        private readonly ApplicationSettingService $settings,
        private readonly ?string $managedDirectory = null,
    ) {}

    public function configuredPolicy(): BackupRetentionPolicy
    {
        $maximumStorageBytes = $this->settings->get(Setting::BACKUP_MAXIMUM_STORAGE_BYTES);

        if (! is_null($maximumStorageBytes) && ! is_int($maximumStorageBytes)) {
            throw new BackupArchiveException('The configured backup storage limit is invalid.');
        }

        return new BackupRetentionPolicy(
            daily: $this->integerSetting(Setting::BACKUP_RETENTION_DAILY),
            weekly: $this->integerSetting(Setting::BACKUP_RETENTION_WEEKLY),
            monthly: $this->integerSetting(Setting::BACKUP_RETENTION_MONTHLY),
            maximumStorageBytes: $maximumStorageBytes,
        );
    }

    public function preview(?BackupRetentionPolicy $policy = null): LocalBackupRetentionPreview
    {
        return $this->snapshot($policy ?? $this->configuredPolicy())->preview;
    }

    public function issueConfirmation(LocalBackupRetentionPreview $preview): string
    {
        return $this->confirmation->issue($preview);
    }

    /** @return array<string, mixed> */
    public function apply(
        string $confirmationToken,
        bool $internalConfirmation,
        ?BackupRetentionPolicy $policy = null,
    ): array {
        if (! $internalConfirmation || trim($confirmationToken) === '') {
            throw new BackupArchiveException(
                'Retention apply requires the explicit internal confirmation flag and a matching token.',
            );
        }

        $lock = Cache::lock('medismart:local-backup-retention', 600);

        if (! $lock->get()) {
            throw new BackupArchiveException('Another local backup retention operation is already active.');
        }

        try {
            $effectivePolicy = $policy ?? $this->configuredPolicy();
            $authorizedSnapshot = $this->snapshot($effectivePolicy);
            $this->confirmation->assertValid($confirmationToken, $authorizedSnapshot->preview);
            $deletedRecordIds = [];

            foreach ($authorizedSnapshot->preview->plan->deletionCandidates as $authorizedDecision) {
                $managedFileId = $authorizedDecision['managed_file_id'] ?? null;
                $authorizedFingerprint = $authorizedDecision['metadata_fingerprint'] ?? null;

                if (! is_string($managedFileId) || ! is_string($authorizedFingerprint)) {
                    throw new BackupArchiveException('The authorized retention plan contains an invalid candidate.');
                }

                // A successful earlier deletion changes the inventory hash, so
                // the original token cannot simply be replayed against this
                // pass. Replan the complete remaining set and allow only the
                // same originally authorized artifact if it is still a fresh
                // deletion candidate with the exact metadata fingerprint.
                $snapshot = $this->snapshot($effectivePolicy);
                $decision = $this->freshDecision($snapshot, $managedFileId);
                $managedFileId = $decision['managed_file_id'] ?? null;
                $metadataFingerprint = $decision['metadata_fingerprint'] ?? null;
                $reasonCode = $decision['reason_code'] ?? null;

                if (! is_string($managedFileId)
                    || ! is_string($metadataFingerprint)
                    || ! is_string($reasonCode)
                    || ! hash_equals($authorizedFingerprint, $metadataFingerprint)
                    || ! isset($snapshot->candidates[$managedFileId])) {
                    throw new BackupArchiveException(
                        'An authorized backup is no longer an exact fresh retention candidate.',
                    );
                }

                $this->deleteCandidate(
                    $snapshot,
                    $snapshot->candidates[$managedFileId],
                    $metadataFingerprint,
                    $reasonCode,
                );
                $deletedRecordIds[] = $snapshot->candidates[$managedFileId]['record_id'];
            }

            return [
                'mode' => 'apply',
                'plan_sha256' => $authorizedSnapshot->preview->plan->planSha256,
                'inventory_sha256' => $authorizedSnapshot->preview->inventorySha256,
                'deleted_count' => count($deletedRecordIds),
                'deleted_record_ids' => $deletedRecordIds,
                'deletion_authorized' => true,
                'destructive_actions_performed' => $deletedRecordIds !== [],
            ];
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function freshDecision(
        LocalBackupRetentionSnapshot $snapshot,
        string $managedFileId,
    ): array {
        foreach ($snapshot->preview->plan->deletionCandidates as $decision) {
            if (($decision['managed_file_id'] ?? null) === $managedFileId) {
                return $decision;
            }
        }

        throw new BackupArchiveException(
            'An authorized backup is no longer a deletion candidate after fresh replanning.',
        );
    }

    private function integerSetting(string $key): int
    {
        $value = $this->settings->get($key);

        if (! is_int($value)) {
            throw new BackupArchiveException('A configured backup retention count is invalid.');
        }

        return $value;
    }

    private function snapshot(BackupRetentionPolicy $policy): LocalBackupRetentionSnapshot
    {
        $root = $this->validatedManagedRoot();
        $nodes = $this->scanDirectory($root);
        $filesByRelative = [];

        foreach ($nodes as $node) {
            if ($node['kind'] !== 'directory') {
                $filesByRelative[$node['relative']] = $node;
            }
        }

        /** @var array<string, list<BackupRecord>> $recordsByRelative */
        $recordsByRelative = [];
        /** @var list<array<string, mixed>> $recordDescriptors */
        $recordDescriptors = [];
        /** @var list<array{record: BackupRecord, relative: string|null}> $recordStates */
        $recordStates = [];
        /** @var array<string, list<string>> $possibleReferences */
        $possibleReferences = [];
        $protectedEntries = [];
        $physicalProtectionReasons = [];
        $records = BackupRecord::query()->orderBy('id')->get();

        foreach ($records as $record) {
            $recordDescriptors[] = $this->recordDescriptor($record);
            $relative = $this->exactManagedRelative($record, $root);
            $recordStates[] = ['record' => $record, 'relative' => $relative];
            $possibleReference = $this->possibleManagedReference($record, $root);

            if ($possibleReference !== null) {
                $possibleReferences[$possibleReference][] = $record->id;
            }
        }

        $conflictingRecordIds = [];

        foreach ($possibleReferences as $referenceIds) {
            if (count($referenceIds) > 1) {
                foreach ($referenceIds as $recordId) {
                    $conflictingRecordIds[$recordId] = true;
                }
            }
        }

        foreach ($recordStates as $state) {
            $record = $state['record'];
            $relative = $state['relative'];

            if ($relative === null) {
                $protectedEntries[] = $this->protectedRecord(
                    $record,
                    isset($conflictingRecordIds[$record->id])
                        ? 'conflicting_backup_records'
                        : 'record_path_not_exactly_managed',
                );

                continue;
            }

            $recordsByRelative[$relative][] = $record;
        }

        ksort($recordsByRelative, SORT_STRING);
        $verifiedInputs = [];
        $candidateMap = [];
        $eligiblePhysical = [];

        foreach ($recordsByRelative as $relative => $pathRecords) {
            $fileRef = $this->fileRef($relative);
            $hasConflictingReference = false;

            foreach ($pathRecords as $record) {
                if (isset($conflictingRecordIds[$record->id])) {
                    $hasConflictingReference = true;
                    break;
                }
            }

            if (count($pathRecords) !== 1 || $hasConflictingReference) {
                foreach ($pathRecords as $record) {
                    $protectedEntries[] = $this->protectedRecord(
                        $record,
                        'conflicting_backup_records',
                        $fileRef,
                    );
                }

                $physicalProtectionReasons[$relative] = 'conflicting_backup_records';

                continue;
            }

            $record = $pathRecords[0];
            $node = $filesByRelative[$relative] ?? null;
            $reason = $this->ineligibleReason($record, $relative, $node);

            if ($reason !== null) {
                $protectedEntries[] = $this->protectedRecord($record, $reason, $fileRef);
                $physicalProtectionReasons[$relative] = $reason;

                continue;
            }

            if (! is_array($node)) {
                throw new BackupArchiveException('The managed backup inventory changed unexpectedly.');
            }

            try {
                $metadata = $this->inspector->inspect($node['path']);
            } catch (Throwable) {
                $protectedEntries[] = $this->protectedRecord(
                    $record,
                    'archive_revalidation_failed',
                    $fileRef,
                );
                $physicalProtectionReasons[$relative] = 'archive_revalidation_failed';

                continue;
            }

            if (! is_int($record->size)
                || ! is_string($record->sha256)
                || preg_match('/\A[a-f0-9]{64}\z/', $record->sha256) !== 1
                || $record->size !== $metadata['size_bytes']
                || ! hash_equals($record->sha256, $metadata['sha256'])) {
                $protectedEntries[] = $this->protectedRecord(
                    $record,
                    'record_file_verification_mismatch',
                    $fileRef,
                );
                $physicalProtectionReasons[$relative] = 'record_file_verification_mismatch';

                continue;
            }

            $verifiedInputs[] = [
                'id' => $record->id,
                'name' => $record->filename,
                'size_bytes' => $metadata['size_bytes'],
                'created_at' => $metadata['created_at'],
                'sha256' => $metadata['sha256'],
                'backup_record_id' => $record->id,
                'format' => VerifiedBackupMetadata::FORMAT,
                'format_version' => $metadata['format_version'],
                'verification_status' => 'verified',
                'verified_sha256' => $metadata['sha256'],
            ];
            $candidateMap[$record->id] = [
                'record_id' => $record->id,
                'path' => $node['path'],
                'record_fingerprint' => $this->fingerprint($this->recordDescriptor($record)),
                'metadata' => $metadata,
            ];
            $eligiblePhysical[$node['physical_id']] = true;
        }

        $protectedStorageBytes = 0;
        $countedPhysical = [];

        foreach ($nodes as $node) {
            if ($node['kind'] === 'directory' || isset($eligiblePhysical[$node['physical_id']])) {
                continue;
            }

            if (! isset($countedPhysical[$node['physical_id']])) {
                $protectedStorageBytes = $this->addBytes($protectedStorageBytes, $node['size']);
                $countedPhysical[$node['physical_id']] = true;
            }

            $protectedEntries[] = [
                'record_id' => null,
                'file_ref' => $node['file_ref'],
                'reason_code' => $physicalProtectionReasons[$node['relative']]
                    ?? $this->unownedReason($node['relative'], $node['kind']),
                'size_bytes' => $node['size'],
            ];
        }

        usort(
            $protectedEntries,
            static fn (array $left, array $right): int => strcmp(
                ($left['file_ref'] ?? '').'|'.($left['record_id'] ?? '').'|'.$left['reason_code'],
                ($right['file_ref'] ?? '').'|'.($right['record_id'] ?? '').'|'.$right['reason_code'],
            ),
        );
        $plan = $this->planner->plan($verifiedInputs, $policy, $protectedStorageBytes);

        if ($plan->protected !== []) {
            throw new BackupArchiveException('A locally verified retention entry became malformed during planning.');
        }

        $inventoryPayload = [
            'version' => 1,
            'managed_root_sha256' => hash('sha256', $root),
            'nodes' => array_map(
                static fn (array $node): array => [
                    'file_ref' => $node['file_ref'],
                    'kind' => $node['kind'],
                    'size' => $node['size'],
                    'mode' => $node['mode'],
                    'nlink' => $node['nlink'],
                    'device' => $node['device'],
                    'inode' => $node['inode'],
                    'mtime' => $node['mtime'],
                ],
                $nodes,
            ),
            'records' => $recordDescriptors,
            'verified_inputs' => $verifiedInputs,
            'protected_entries' => $protectedEntries,
            'protected_storage_bytes' => $protectedStorageBytes,
        ];
        $inventorySha256 = $this->fingerprint($inventoryPayload);
        $preview = new LocalBackupRetentionPreview(
            plan: $plan,
            protectedEntries: $protectedEntries,
            inventorySha256: $inventorySha256,
            managedRootSha256: hash('sha256', $root),
        );

        return new LocalBackupRetentionSnapshot($preview, $root, $candidateMap);
    }

    /**
     * @param  array{
     *     record_id: string,
     *     path: string,
     *     record_fingerprint: string,
     *     metadata: array{format_version: 1|2, created_at: string, size_bytes: int, sha256: string}
     * }  $candidate
     */
    private function deleteCandidate(
        LocalBackupRetentionSnapshot $snapshot,
        array $candidate,
        string $metadataFingerprint,
        string $reasonCode,
    ): void {
        $record = BackupRecord::query()->find($candidate['record_id']);

        if (! $record instanceof BackupRecord
            || ! hash_equals(
                $candidate['record_fingerprint'],
                $this->fingerprint($this->recordDescriptor($record)),
            )
            || $this->exactManagedRelative($record, $snapshot->managedRoot) === null
            || $record->local_path !== $candidate['path']) {
            throw new BackupArchiveException('A candidate backup record changed before retention apply.');
        }

        $freshMetadata = $this->inspector->inspect($candidate['path']);

        if ($freshMetadata !== $candidate['metadata']) {
            throw new BackupArchiveException('A candidate backup file changed before retention apply.');
        }

        $expectedPlanMetadata = VerifiedBackupMetadata::fromUntrusted([
            'id' => $record->id,
            'name' => $record->filename,
            'size_bytes' => $freshMetadata['size_bytes'],
            'created_at' => $freshMetadata['created_at'],
            'sha256' => $freshMetadata['sha256'],
            'backup_record_id' => $record->id,
            'format' => VerifiedBackupMetadata::FORMAT,
            'format_version' => $freshMetadata['format_version'],
            'verification_status' => 'verified',
            'verified_sha256' => $freshMetadata['sha256'],
        ])['metadata'];

        if (! $expectedPlanMetadata instanceof VerifiedBackupMetadata
            || ! hash_equals(
                $metadataFingerprint,
                $expectedPlanMetadata->toPlanMetadata()['metadata_fingerprint'],
            )) {
            throw new BackupArchiveException('A candidate backup fingerprint changed before retention apply.');
        }

        $tombstone = $snapshot->managedRoot.DIRECTORY_SEPARATOR
            .'.medismart-retention-'.$record->id.'-'.substr($metadataFingerprint, 0, 16).'.pending.msbackup';

        if (@lstat($tombstone) !== false) {
            throw new BackupArchiveException('A prior retention tombstone requires manual review.');
        }

        $transitioned = BackupRecord::query()
            ->whereKey($record->id)
            ->where('disk', 'local')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('local_path', $candidate['path'])
            ->where('size', $freshMetadata['size_bytes'])
            ->where('sha256', $freshMetadata['sha256'])
            ->update(['status' => 'retention_pending_delete']);

        if ($transitioned !== 1) {
            throw new BackupArchiveException('The candidate backup record could not enter retention deletion.');
        }

        if (! @rename($candidate['path'], $tombstone)) {
            $this->restorePendingRecord($record->id, $candidate['path'], $tombstone);
            throw new BackupArchiveException('The candidate backup could not be isolated for retention deletion.');
        }

        $pathUpdated = BackupRecord::query()
            ->whereKey($record->id)
            ->where('status', 'retention_pending_delete')
            ->where('local_path', $candidate['path'])
            ->update(['local_path' => $tombstone]);

        if ($pathUpdated !== 1) {
            $this->restorePendingRecord($record->id, $candidate['path'], $tombstone);
            throw new BackupArchiveException('The isolated backup could not be recorded consistently.');
        }

        try {
            $isolatedMetadata = $this->inspector->inspect($tombstone);

            if ($isolatedMetadata !== $freshMetadata) {
                throw new BackupArchiveException('The isolated backup changed before deletion.');
            }

            if (! @unlink($tombstone)) {
                throw new BackupArchiveException('The isolated backup could not be deleted.');
            }
        } catch (Throwable $exception) {
            $this->restorePendingRecord($record->id, $candidate['path'], $tombstone);
            throw $exception;
        }

        $completed = BackupRecord::query()
            ->whereKey($record->id)
            ->where('status', 'retention_pending_delete')
            ->where('local_path', $tombstone)
            ->update([
                'status' => 'retention_deleted',
                'local_path' => null,
            ]);

        if ($completed !== 1) {
            throw new BackupArchiveException(
                'The deleted backup record requires consistency recovery before retention can continue.',
            );
        }

        $record = $record->refresh();
        AuditLog::record('backup.retention_deleted', $record, [
            'reason_code' => $reasonCode,
            'size' => $freshMetadata['size_bytes'],
            'sha256' => $freshMetadata['sha256'],
            'format_version' => $freshMetadata['format_version'],
        ]);
        ApplicationEvent::record('BackupRetentionDeleted', context: [
            'backup_record_id' => $record->id,
            'reason_code' => $reasonCode,
        ]);
    }

    private function restorePendingRecord(string $recordId, string $original, string $tombstone): void
    {
        clearstatcache(true, $original);
        clearstatcache(true, $tombstone);

        if (@lstat($tombstone) !== false && @lstat($original) === false) {
            @rename($tombstone, $original);
        }

        if (@lstat($original) !== false && @lstat($tombstone) === false) {
            BackupRecord::query()
                ->whereKey($recordId)
                ->where('status', 'retention_pending_delete')
                ->update([
                    'status' => 'completed',
                    'local_path' => $original,
                ]);
        }
    }

    private function validatedManagedRoot(): string
    {
        $configured = $this->managedDirectory
            ?? config('medismart.backups.managed_directory', storage_path('app/private/backups'));

        if (! is_string($configured) || $configured === '' || str_contains($configured, "\0")) {
            throw new BackupArchiveException('The managed local backup directory is invalid.');
        }

        $configured = rtrim($configured, DIRECTORY_SEPARATOR);
        $real = realpath($configured);
        $stat = @lstat($configured);

        if (! is_string($real)
            || $real !== $configured
            || ! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0040000) {
            throw new BackupArchiveException('The managed local backup directory is unavailable or unsafe.');
        }

        $this->assertNoSymlinkComponents($real);

        return $real;
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            if (preg_match('/\A[A-Za-z]:\\\\/', $path) !== 1) {
                throw new BackupArchiveException(
                    'The managed local backup directory must be an absolute local path.',
                );
            }

            $cursor = substr($path, 0, 3);
            $remainder = substr($path, 3);
        } else {
            if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                throw new BackupArchiveException('The managed local backup directory must be absolute.');
            }

            $cursor = DIRECTORY_SEPARATOR;
            $remainder = ltrim($path, DIRECTORY_SEPARATOR);
        }

        foreach (array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, $remainder),
            static fn (string $component): bool => $component !== '',
        )) as $component) {
            $cursor = rtrim($cursor, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$component;
            $stat = @lstat($cursor);

            if (! is_array($stat) || ($stat['mode'] & 0170000) === 0120000) {
                throw new BackupArchiveException('The managed local backup path contains an unsafe link.');
            }
        }
    }

    /**
     * @return list<array{
     *     relative: string,
     *     path: string,
     *     file_ref: string,
     *     physical_id: string,
     *     kind: 'directory'|'regular'|'symlink'|'other',
     *     size: int,
     *     mode: int,
     *     nlink: int,
     *     device: int,
     *     inode: int,
     *     mtime: int
     * }>
     */
    private function scanDirectory(string $root, string $relativeDirectory = ''): array
    {
        $directory = $relativeDirectory === ''
            ? $root
            : $root.DIRECTORY_SEPARATOR.$relativeDirectory;
        $names = @scandir($directory, SCANDIR_SORT_ASCENDING);

        if (! is_array($names)) {
            throw new BackupArchiveException('The managed backup inventory could not be read safely.');
        }

        $nodes = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relative = $relativeDirectory === ''
                ? $name
                : $relativeDirectory.DIRECTORY_SEPARATOR.$name;
            $path = $root.DIRECTORY_SEPARATOR.$relative;
            $stat = @lstat($path);

            if (! is_array($stat) || $stat['size'] < 0) {
                throw new BackupArchiveException('A managed backup filesystem entry could not be inspected.');
            }

            $type = $stat['mode'] & 0170000;
            $kind = match ($type) {
                0040000 => 'directory',
                0100000 => 'regular',
                0120000 => 'symlink',
                default => 'other',
            };
            $nodes[] = [
                'relative' => $relative,
                'path' => $path,
                'file_ref' => $this->fileRef($relative),
                'physical_id' => $stat['dev'].':'.$stat['ino'],
                'kind' => $kind,
                'size' => $stat['size'],
                'mode' => $stat['mode'],
                'nlink' => $stat['nlink'],
                'device' => $stat['dev'],
                'inode' => $stat['ino'],
                'mtime' => $stat['mtime'],
            ];

            if ($kind === 'directory') {
                array_push($nodes, ...$this->scanDirectory($root, $relative));
            }
        }

        return $nodes;
    }

    private function exactManagedRelative(BackupRecord $record, string $root): ?string
    {
        if ($record->filename === ''
            || strlen($record->filename) > 255
            || basename(str_replace('\\', '/', $record->filename)) !== $record->filename
            || preg_match('/[\x00-\x1F\x7F]/', $record->filename) === 1
            || ! is_string($record->local_path)) {
            return null;
        }

        $expected = $root.DIRECTORY_SEPARATOR.$record->filename;

        return hash_equals($expected, $record->local_path) ? $record->filename : null;
    }

    /**
     * Conservatively maps any row path lexically below the managed root to a
     * possible direct-child target by basename. This never opens the supplied
     * row path. A malformed alias can therefore protect an otherwise valid
     * file, but it can never make one eligible.
     */
    private function possibleManagedReference(BackupRecord $record, string $root): ?string
    {
        if (! is_string($record->local_path)) {
            return null;
        }

        $prefix = $root.DIRECTORY_SEPARATOR;
        $insideRoot = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($record->local_path, $prefix, strlen($prefix)) === 0
            : str_starts_with($record->local_path, $prefix);

        if (! $insideRoot) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $record->local_path));

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }

        return $root.DIRECTORY_SEPARATOR.$basename;
    }

    /** @param array<string, mixed>|null $node */
    private function ineligibleReason(
        BackupRecord $record,
        string $relative,
        ?array $node,
    ): ?string {
        if ($record->disk !== 'local') {
            return 'non_local_backup_record';
        }

        if ($record->status !== 'completed' || $record->completed_at === null) {
            return 'non_completed_backup_record';
        }

        if ($this->isPreRestoreSafetyArchive($relative)) {
            return 'pre_restore_safety_archive';
        }

        if (! str_ends_with($relative, '.msbackup')) {
            return 'unsupported_backup_record_format';
        }

        if ($node === null) {
            return 'managed_backup_file_missing';
        }

        if (($node['kind'] ?? null) !== 'regular' || ($node['nlink'] ?? null) !== 1) {
            return 'managed_backup_file_not_unique_regular';
        }

        return null;
    }

    private function unownedReason(string $relative, string $kind): string
    {
        if ($relative === 'pre-restore-safety'
            || str_starts_with($relative, 'pre-restore-safety'.DIRECTORY_SEPARATOR)
            || $this->isPreRestoreSafetyArchive(basename($relative))) {
            return 'pre_restore_safety_archive';
        }

        if ($kind === 'symlink') {
            return 'unmanaged_symbolic_link';
        }

        if ($kind !== 'regular') {
            return 'unmanaged_non_regular_entry';
        }

        return 'unowned_managed_directory_file';
    }

    private function isPreRestoreSafetyArchive(string $path): bool
    {
        return str_starts_with($path, 'Drclick-Pre-Restore-Safety-')
            || str_starts_with($path, 'MediSmart-Pre-Restore-Safety-');
    }

    /** @return array{record_id: string|null, file_ref: string|null, reason_code: string, size_bytes: int} */
    private function protectedRecord(
        BackupRecord $record,
        string $reason,
        ?string $fileRef = null,
    ): array {
        return [
            'record_id' => $record->id,
            'file_ref' => $fileRef,
            'reason_code' => $reason,
            'size_bytes' => 0,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function recordDescriptor(BackupRecord $record): array
    {
        return [
            'id' => $record->id,
            'filename' => $record->filename,
            'disk' => $record->disk,
            'local_path_ref' => is_string($record->local_path)
                ? hash('sha256', $record->local_path)
                : null,
            'remote_file_id_ref' => is_string($record->remote_file_id)
                ? hash('sha256', $record->remote_file_id)
                : null,
            'size' => $record->size,
            'sha256' => $record->sha256,
            'schema_version' => $record->schema_version,
            'application_version' => $record->application_version,
            'status' => $record->status,
            'started_at' => $record->started_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'completed_at' => $record->completed_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'created_by' => $record->created_by,
            'created_at' => $record->created_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'updated_at' => $record->updated_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function fileRef(string $relative): string
    {
        return hash('sha256', str_replace(DIRECTORY_SEPARATOR, '/', $relative));
    }

    private function fingerprint(mixed $value): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new BackupArchiveException('The local retention inventory could not be fingerprinted.');
        }

        return hash('sha256', $json);
    }

    private function addBytes(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }
}
