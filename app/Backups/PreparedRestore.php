<?php

namespace App\Backups;

use Illuminate\Support\Str;
use JsonException;

final readonly class PreparedRestore
{
    private const PLAN_VERSION = 1;

    public const NATIVE_AUTHORIZATION_PROTOCOL = 'medismart-offline-restore-authorization';

    public const NATIVE_AUTHORIZATION_VERSION = 1;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{path: string, size: int, sha256: string}>  $inventory
     */
    private function __construct(
        public string $operationId,
        public string $workspace,
        public string $journalPath,
        public string $encryptedArchiveSha256,
        public string $innerArchiveSha256,
        public array $manifest,
        public int $stagedFileCount,
        public int $stagedBytes,
        public array $inventory,
        public string $planSha256,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{path: string, size: int, sha256: string}>  $inventory
     */
    public static function publish(
        string $operationId,
        string $workspace,
        string $journalPath,
        string $encryptedArchiveSha256,
        string $innerArchiveSha256,
        array $manifest,
        int $stagedFileCount,
        int $stagedBytes,
        array $inventory,
    ): self {
        self::assertOperationId($operationId);
        $workspace = self::validatedWorkspace($operationId, $workspace);
        $plan = [
            'plan_version' => self::PLAN_VERSION,
            'operation_id' => $operationId,
            'encrypted_archive_sha256' => $encryptedArchiveSha256,
            'inner_archive_sha256' => $innerArchiveSha256,
            'manifest' => $manifest,
            'staged_file_count' => $stagedFileCount,
            'staged_bytes' => $stagedBytes,
            'inventory' => $inventory,
        ];
        self::assertPlan($plan);
        $encodedPlan = self::encode($plan);
        $planSha256 = hash('sha256', $encodedPlan);
        $document = self::encode([
            'plan' => $plan,
            'sha256' => $planSha256,
        ])."\n";
        $path = $workspace.DIRECTORY_SEPARATOR.'restore-plan.json';
        $handle = @fopen($path, 'xb');

        if (! is_resource($handle)) {
            throw new BackupArchiveException('The prepared restore plan could not be created.');
        }

        try {
            self::secureFile($path);
            $written = fwrite($handle, $document);

            if (! is_int($written) || $written !== strlen($document)
                || ! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new BackupArchiveException('The prepared restore plan could not be persisted durably.');
            }
        } finally {
            fclose($handle);
        }

        return new self(
            $operationId,
            $workspace,
            $journalPath,
            $encryptedArchiveSha256,
            $innerArchiveSha256,
            $manifest,
            $stagedFileCount,
            $stagedBytes,
            $inventory,
            $planSha256,
        );
    }

    public static function load(string $operationId): self
    {
        self::assertOperationId($operationId);
        $workspace = self::validatedWorkspace(
            $operationId,
            storage_path('app/private/restore-work/'.$operationId),
        );
        $journal = RestoreRecoveryJournal::open($operationId);
        $path = $workspace.DIRECTORY_SEPARATOR.'restore-plan.json';

        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new BackupArchiveException('The prepared restore plan is unavailable.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size < 2 || $size > MsBackupArchiveVerifier::MAXIMUM_METADATA_BYTES) {
            throw new BackupArchiveException('The prepared restore plan is malformed.');
        }

        $json = file_get_contents($path);

        try {
            $document = is_string($json)
                ? json_decode($json, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            throw new BackupArchiveException('The prepared restore plan is malformed.');
        }

        if (! is_array($document) || array_is_list($document)
            || ! is_array($document['plan'] ?? null) || array_is_list($document['plan'])
            || ! is_string($document['sha256'] ?? null)) {
            throw new BackupArchiveException('The prepared restore plan is malformed.');
        }

        $plan = $document['plan'];
        self::assertPlan($plan);

        if ($plan['operation_id'] !== $operationId) {
            throw new BackupArchiveException('The prepared restore plan operation identifier does not match its workspace.');
        }

        $checksum = hash('sha256', self::encode($plan));

        if (! hash_equals($checksum, $document['sha256'])) {
            throw new BackupArchiveException('The prepared restore plan failed integrity validation.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = $plan['manifest'];

        return new self(
            $operationId,
            $workspace,
            $journal->path,
            $plan['encrypted_archive_sha256'],
            $plan['inner_archive_sha256'],
            $manifest,
            $plan['staged_file_count'],
            $plan['staged_bytes'],
            $plan['inventory'],
            $checksum,
        );
    }

    public function stagedDatabasePath(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'staged'.DIRECTORY_SEPARATOR.'database.sqlite3';
    }

    /** @return array{protocol: string, version: int, operation_id: string, plan_sha256: string} */
    public function nativeAuthorizationArtifact(): array
    {
        return [
            'protocol' => self::NATIVE_AUTHORIZATION_PROTOCOL,
            'version' => self::NATIVE_AUTHORIZATION_VERSION,
            'operation_id' => $this->operationId,
            'plan_sha256' => $this->planSha256,
        ];
    }

    /** @return array{clinical_documents: string, patient_documents: string, medical_models: string, cabinet: string} */
    public function stagedManagedRoots(): array
    {
        $staged = $this->workspace.DIRECTORY_SEPARATOR.'staged';

        return [
            'clinical_documents' => $staged.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'clinical-documents',
            'patient_documents' => $staged.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'patient-documents',
            'medical_models' => $staged.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical-models',
            'cabinet' => $staged.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'cabinet',
        ];
    }

    /** @param array<string, mixed> $plan */
    private static function assertPlan(array $plan): void
    {
        if (($plan['plan_version'] ?? null) !== self::PLAN_VERSION
            || ! is_string($plan['operation_id'] ?? null) || ! Str::isUuid($plan['operation_id'])
            || ! is_string($plan['encrypted_archive_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $plan['encrypted_archive_sha256']) !== 1
            || ! is_string($plan['inner_archive_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $plan['inner_archive_sha256']) !== 1
            || ! is_array($plan['manifest'] ?? null) || array_is_list($plan['manifest'])
            || ! is_int($plan['staged_file_count'] ?? null) || $plan['staged_file_count'] < 1
            || ! is_int($plan['staged_bytes'] ?? null) || $plan['staged_bytes'] < 1
            || ! is_array($plan['inventory'] ?? null) || ! array_is_list($plan['inventory'])) {
            throw new BackupArchiveException('The prepared restore plan is malformed.');
        }

        $portablePaths = [];
        $inventoryBytes = 0;

        foreach ($plan['inventory'] as $item) {
            if (! is_array($item) || array_is_list($item)
                || ! is_string($item['path'] ?? null)
                || ! is_int($item['size'] ?? null) || $item['size'] < 0
                || ! is_string($item['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $item['sha256']) !== 1) {
                throw new BackupArchiveException('The prepared restore inventory is malformed.');
            }

            BackupArchivePath::assertSafe($item['path']);
            $portable = BackupArchivePath::portableKey($item['path']);

            if (isset($portablePaths[$portable])) {
                throw new BackupArchiveException('The prepared restore inventory contains duplicate paths.');
            }

            $portablePaths[$portable] = true;
            $inventoryBytes += $item['size'];
        }

        if (count($plan['inventory']) !== $plan['staged_file_count']
            || $inventoryBytes !== $plan['staged_bytes']) {
            throw new BackupArchiveException('The prepared restore inventory does not match its declared totals.');
        }
    }

    private static function validatedWorkspace(string $operationId, string $workspace): string
    {
        $resolved = realpath($workspace);
        $root = realpath(storage_path('app/private/restore-work'));

        if (! is_string($resolved) || ! is_string($root)
            || is_link($workspace)
            || basename($resolved) !== $operationId
            || dirname($resolved) !== rtrim($root, DIRECTORY_SEPARATOR)) {
            throw new BackupArchiveException('The prepared restore workspace is invalid.');
        }

        return $resolved;
    }

    private static function assertOperationId(string $operationId): void
    {
        if (! Str::isUuid($operationId)) {
            throw new BackupArchiveException('The restore operation identifier is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new BackupArchiveException('The prepared restore plan could not be encoded.');
        }
    }

    private static function secureFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
            throw new BackupArchiveException('The prepared restore plan could not be secured.');
        }
    }
}
