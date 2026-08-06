<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Str;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class AbandonedRestorePreparationPruner
{
    private const MINIMUM_RETENTION_HOURS = 24;

    private const MAXIMUM_RETENTION_HOURS = 8760;

    private const READY_EVENT = 'ready_for_offline_apply';

    public function __construct(
        private readonly RestoreLifecycleLock $lifecycleLock,
    ) {}

    /** @param null|Closure(string): void $beforeDelete */
    public function prune(?Closure $beforeDelete = null): AbandonedRestorePruneReport
    {
        $retentionHours = $this->retentionHours();
        $lease = $this->lifecycleLock->tryAcquire();

        if (! $lease instanceof RestoreLifecycleLease) {
            return new AbandonedRestorePruneReport($retentionHours, false);
        }

        try {
            return $this->pruneWhileLocked($retentionHours, $beforeDelete);
        } finally {
            $lease->release();
        }
    }

    /** @param null|Closure(string): void $beforeDelete */
    private function pruneWhileLocked(
        int $retentionHours,
        ?Closure $beforeDelete,
    ): AbandonedRestorePruneReport {
        $workspaceRoot = $this->managedRoot(storage_path('app/private/restore-work'));
        $journalRoot = $this->managedRoot(storage_path('app/private/restore-journals'));
        $workspaces = $this->workspaceEntries($workspaceRoot);
        $journals = $this->journalEntries($journalRoot);
        $counts = [
            'matched' => 0,
            'eligible' => 0,
            'pruned' => 0,
            'recent' => 0,
            'state' => 0,
            'mismatch' => 0,
            'unsafe' => $workspaces['unsafe'] + $journals['unsafe'],
            'race' => 0,
            'failures' => 0,
        ];
        $operationIds = array_values(array_unique([
            ...array_keys($workspaces['nodes']),
            ...array_keys($journals['nodes']),
        ]));
        sort($operationIds, SORT_STRING);
        $cutoff = CarbonImmutable::now()->subHours($retentionHours);

        foreach ($operationIds as $operationId) {
            $workspace = $workspaces['nodes'][$operationId] ?? null;
            $journal = $journals['nodes'][$operationId] ?? null;

            if (! is_array($workspace) || ! is_array($journal)) {
                $counts['mismatch']++;

                continue;
            }

            $counts['matched']++;
            $assessment = $this->assess($operationId, $workspace, $journal, $cutoff);

            if ($assessment['status'] !== 'eligible') {
                $this->countProtection($counts, $assessment['status']);

                continue;
            }

            $counts['eligible']++;

            try {
                if ($beforeDelete instanceof Closure) {
                    $beforeDelete($operationId);
                }

                $fresh = $this->assess($operationId, $workspace, $journal, $cutoff);

                if ($fresh['status'] !== 'eligible'
                    || ! is_string($assessment['fingerprint'])
                    || ! is_string($fresh['fingerprint'])
                    || ! hash_equals($assessment['fingerprint'], $fresh['fingerprint'])) {
                    $counts['race']++;

                    continue;
                }

                $this->deletePair($workspaceRoot, $journalRoot, $workspace, $journal, $fresh);
                $counts['pruned']++;
            } catch (Throwable) {
                $counts['failures']++;
            }
        }

        return new AbandonedRestorePruneReport(
            retentionHours: $retentionHours,
            lockAcquired: true,
            workspaceEntries: $workspaces['entries'],
            journalEntries: $journals['entries'],
            matchedPairs: $counts['matched'],
            eligiblePairs: $counts['eligible'],
            prunedPairs: $counts['pruned'],
            protectedRecent: $counts['recent'],
            protectedState: $counts['state'],
            protectedMismatch: $counts['mismatch'],
            protectedUnsafe: $counts['unsafe'],
            raceChanged: $counts['race'],
            failures: $counts['failures'],
        );
    }

    /**
     * @param  array{matched: int, eligible: int, pruned: int, recent: int, state: int, mismatch: int, unsafe: int, race: int, failures: int}  $counts
     */
    private function countProtection(array &$counts, string $status): void
    {
        if (array_key_exists($status, $counts)) {
            $counts[$status]++;
        } else {
            $counts['mismatch']++;
        }
    }

    /**
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $workspace
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $journal
     * @return array{status: 'eligible'|'recent'|'state'|'mismatch'|'unsafe', fingerprint: string|null, journal_identity: string|null}
     */
    private function assess(
        string $operationId,
        array $workspace,
        array $journal,
        CarbonImmutable $cutoff,
    ): array {
        if (! $this->identityMatches($workspace, 'directory')
            || ! $this->identityMatches($journal, 'file')) {
            return $this->assessment('mismatch');
        }

        try {
            $records = RestoreRecoveryJournal::open($operationId)->records();
            $last = end($records);
        } catch (BackupArchiveException) {
            return $this->assessment('mismatch');
        }

        if (! is_array($last) || $last['event'] !== self::READY_EVENT) {
            return $this->assessment('state');
        }

        try {
            $prepared = PreparedRestore::load($operationId);
        } catch (BackupArchiveException) {
            return $this->assessment('mismatch');
        }

        if (($last['context']['plan_sha256'] ?? null) !== $prepared->planSha256) {
            return $this->assessment('mismatch');
        }

        try {
            $tree = $this->verifiedReadyTree($prepared, $workspace, $journal);
            $occurredAt = CarbonImmutable::parse($last['occurred_at']);
        } catch (Throwable) {
            return $this->assessment('unsafe');
        }

        if ($occurredAt->toIso8601String() !== $last['occurred_at']) {
            return $this->assessment('mismatch');
        }

        if ($occurredAt->greaterThan($cutoff) || $tree['latest_mtime'] > $cutoff->getTimestamp()) {
            return $this->assessment('recent');
        }

        try {
            $fingerprint = hash('sha256', json_encode([
                'operation_id' => $operationId,
                'plan_sha256' => $prepared->planSha256,
                'last_record_sha256' => $last['sha256'],
                'nodes' => $tree['nodes'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return $this->assessment('mismatch');
        }

        return [
            'status' => 'eligible',
            'fingerprint' => $fingerprint,
            'journal_identity' => $tree['journal_identity'],
        ];
    }

    /**
     * @return array{status: 'eligible'|'recent'|'state'|'mismatch'|'unsafe', fingerprint: null, journal_identity: null}
     */
    private function assessment(string $status): array
    {
        /** @var 'recent'|'state'|'mismatch'|'unsafe' $status */
        return [
            'status' => $status,
            'fingerprint' => null,
            'journal_identity' => null,
        ];
    }

    /**
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $workspace
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $journal
     * @return array{latest_mtime: int, journal_identity: string, nodes: list<array{relative: string, type: string, dev: int, ino: int, size: int, mtime: int}>}
     */
    private function verifiedReadyTree(
        PreparedRestore $prepared,
        array $workspace,
        array $journal,
    ): array {
        $workspacePath = $workspace['path'];
        $expectedFiles = ['restore-plan.json' => null];
        $expectedDirectories = [
            'staged' => true,
            'staged/private' => true,
            'staged/private/clinical-documents' => true,
            'staged/private/patient-documents' => true,
            'staged/private/medical-models' => true,
            'staged/public' => true,
            'staged/public/cabinet' => true,
        ];

        foreach ($prepared->inventory as $item) {
            $relative = 'staged/'.$item['path'];
            $expectedFiles[$relative] = [
                'size' => $item['size'],
                'sha256' => $item['sha256'],
            ];
            $parent = dirname($relative);

            while ($parent !== '.') {
                $expectedDirectories[str_replace('\\', '/', $parent)] = true;
                $parent = dirname($parent);
            }
        }

        $nodes = [];
        $latestMtime = max($workspace['mtime'], $journal['mtime']);
        $actualFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspacePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isLink()) {
                throw new BackupArchiveException('A prepared restore tree contains a protected symbolic link.');
            }

            $resolved = realpath($path);

            if (! is_string($resolved) || ! $this->isWithin($resolved, $workspacePath)) {
                throw new BackupArchiveException('A prepared restore tree escapes its canonical workspace.');
            }

            $relative = ltrim(str_replace('\\', '/', substr($resolved, strlen($workspacePath))), '/');
            $node = @lstat($path);

            if (! is_array($node) || (! $entry->isDir() && $node['nlink'] !== 1)) {
                throw new BackupArchiveException('A prepared restore tree contains an unsafe filesystem node.');
            }

            if ($entry->isDir()) {
                if (! isset($expectedDirectories[$relative])) {
                    throw new BackupArchiveException('A prepared restore tree contains an unexpected directory.');
                }

                $type = 'directory';
            } elseif ($entry->isFile() && array_key_exists($relative, $expectedFiles)) {
                $type = 'file';
                $expected = $expectedFiles[$relative];

                if (is_array($expected)) {
                    $sha256 = hash_file('sha256', $path);

                    if ($node['size'] !== $expected['size']
                        || ! is_string($sha256)
                        || ! hash_equals($expected['sha256'], $sha256)) {
                        throw new BackupArchiveException('A prepared restore tree no longer matches its inventory.');
                    }
                }

                $actualFiles[$relative] = true;
            } else {
                throw new BackupArchiveException('A prepared restore tree contains an unexpected filesystem node.');
            }

            $latestMtime = max($latestMtime, $node['mtime']);
            $nodes[] = [
                'relative' => $relative,
                'type' => $type,
                'dev' => $node['dev'],
                'ino' => $node['ino'],
                'size' => $node['size'],
                'mtime' => $node['mtime'],
            ];
        }

        $expectedFileNames = array_keys($expectedFiles);
        $actualFileNames = array_keys($actualFiles);
        sort($expectedFileNames, SORT_STRING);
        sort($actualFileNames, SORT_STRING);

        if ($expectedFileNames !== $actualFileNames) {
            throw new BackupArchiveException('A prepared restore tree is incomplete.');
        }

        usort($nodes, static fn (array $left, array $right): int => $left['relative'] <=> $right['relative']);

        return [
            'latest_mtime' => $latestMtime,
            'journal_identity' => $this->identityFingerprint($journal),
            'nodes' => $nodes,
        ];
    }

    /**
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $workspace
     * @param  array{path: string, dev: int, ino: int, size: int, mtime: int}  $journal
     * @param  array{status: string, fingerprint: string|null, journal_identity: string|null}  $assessment
     */
    private function deletePair(
        ?string $workspaceRoot,
        ?string $journalRoot,
        array $workspace,
        array $journal,
        array $assessment,
    ): void {
        if (! is_string($workspaceRoot)
            || ! is_string($journalRoot)
            || ! is_string($assessment['journal_identity'])
            || ! $this->identityMatches($workspace, 'directory')
            || ! $this->identityMatches($journal, 'file')
            || ! hash_equals($assessment['journal_identity'], $this->identityFingerprint($journal))) {
            throw new BackupArchiveException('An abandoned restore pair changed before pruning.');
        }

        $this->deleteWorkspace($workspaceRoot, $workspace['path']);

        if (! $this->identityMatches($journal, 'file')
            || dirname((string) realpath($journal['path'])) !== $journalRoot
            || ! @unlink($journal['path'])) {
            throw new BackupArchiveException('An abandoned restore journal could not be pruned safely.');
        }
    }

    private function deleteWorkspace(string $root, string $workspace): void
    {
        $resolved = realpath($workspace);

        if (! is_string($resolved) || dirname($resolved) !== $root || is_link($workspace)) {
            throw new BackupArchiveException('An abandoned restore workspace is no longer canonical.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isLink()) {
                throw new BackupArchiveException('An abandoned restore workspace became symlinked during pruning.');
            }

            $entryPath = realpath($path);

            if (! is_string($entryPath) || ! $this->isWithin($entryPath, $resolved)) {
                throw new BackupArchiveException('An abandoned restore workspace changed during pruning.');
            }

            $deleted = $entry->isDir() ? @rmdir($entryPath) : @unlink($entryPath);

            if (! $deleted) {
                throw new BackupArchiveException('An abandoned restore workspace could not be pruned completely.');
            }
        }

        if (! @rmdir($resolved)) {
            throw new BackupArchiveException('An abandoned restore workspace could not be removed.');
        }
    }

    /**
     * @return array{entries: int, unsafe: int, nodes: array<string, array{path: string, dev: int, ino: int, size: int, mtime: int}>}
     */
    private function workspaceEntries(?string $root): array
    {
        return $this->directEntries($root, false);
    }

    /**
     * @return array{entries: int, unsafe: int, nodes: array<string, array{path: string, dev: int, ino: int, size: int, mtime: int}>}
     */
    private function journalEntries(?string $root): array
    {
        return $this->directEntries($root, true);
    }

    /**
     * @return array{entries: int, unsafe: int, nodes: array<string, array{path: string, dev: int, ino: int, size: int, mtime: int}>}
     */
    private function directEntries(?string $root, bool $journals): array
    {
        if (! is_string($root)) {
            return ['entries' => 0, 'unsafe' => 0, 'nodes' => []];
        }

        $entries = 0;
        $unsafe = 0;
        $nodes = [];
        $iterator = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entries++;
            $path = $entry->getPathname();
            $filename = $entry->getFilename();
            $operationId = $journals && str_ends_with($filename, '.jsonl')
                ? substr($filename, 0, -6)
                : $filename;
            $expectedFilename = $journals ? $operationId.'.jsonl' : $operationId;

            if ($entry->isLink()
                || $filename !== $expectedFilename
                || ! $this->canonicalUuid($operationId)
                || ($journals ? ! $entry->isFile() : ! $entry->isDir())) {
                $unsafe++;

                continue;
            }

            $identity = $this->nodeIdentity($path, $journals ? 'file' : 'directory');

            if (! is_array($identity) || dirname((string) realpath($path)) !== $root) {
                $unsafe++;

                continue;
            }

            $nodes[$operationId] = $identity;
        }

        return compact('entries', 'unsafe', 'nodes');
    }

    private function managedRoot(string $path): ?string
    {
        if (is_link($path)) {
            throw new BackupArchiveException('A managed restore lifecycle root is symlinked.');
        }

        if (! file_exists($path)) {
            return null;
        }

        $resolved = realpath($path);
        $parent = realpath(dirname($path));

        if (! is_string($resolved)
            || ! is_string($parent)
            || ! is_dir($resolved)
            || ! is_readable($resolved)
            || ! is_writable($resolved)
            || dirname($resolved) !== rtrim($parent, DIRECTORY_SEPARATOR)
            || basename($resolved) !== basename($path)) {
            throw new BackupArchiveException('A managed restore lifecycle root is not canonical.');
        }

        return $resolved;
    }

    /** @return array{path: string, dev: int, ino: int, size: int, mtime: int}|null */
    private function nodeIdentity(string $path, string $type): ?array
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return null;
        }

        $node = @lstat($path);
        $expectedMode = $type === 'directory' ? 0040000 : 0100000;

        if (! is_array($node)
            || ($node['mode'] & 0170000) !== $expectedMode
            || ($type === 'file' && $node['nlink'] !== 1)) {
            return null;
        }

        return [
            'path' => $path,
            'dev' => $node['dev'],
            'ino' => $node['ino'],
            'size' => $node['size'],
            'mtime' => $node['mtime'],
        ];
    }

    /** @param array{path: string, dev: int, ino: int, size: int, mtime: int} $identity */
    private function identityMatches(array $identity, string $type): bool
    {
        $current = $this->nodeIdentity($identity['path'], $type);

        return is_array($current)
            && $current['dev'] === $identity['dev']
            && $current['ino'] === $identity['ino']
            && $current['size'] === $identity['size']
            && $current['mtime'] === $identity['mtime'];
    }

    /** @param array{path: string, dev: int, ino: int, size: int, mtime: int} $identity */
    private function identityFingerprint(array $identity): string
    {
        return implode(':', [
            $identity['dev'],
            $identity['ino'],
            $identity['size'],
            $identity['mtime'],
        ]);
    }

    private function canonicalUuid(string $operationId): bool
    {
        return Str::isUuid($operationId, 4)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $operationId) === 1;
    }

    private function isWithin(string $path, string $root): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }

        return str_starts_with($normalizedPath, $normalizedRoot.'/');
    }

    private function retentionHours(): int
    {
        $hours = config('medismart.backups.prepared_restore_retention_hours', 168);

        if (! is_int($hours)
            || $hours < self::MINIMUM_RETENTION_HOURS
            || $hours > self::MAXIMUM_RETENTION_HOURS) {
            throw new BackupArchiveException('The prepared restore retention period is invalid.');
        }

        return $hours;
    }
}
