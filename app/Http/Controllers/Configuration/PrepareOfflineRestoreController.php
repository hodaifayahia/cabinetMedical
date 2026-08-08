<?php

namespace App\Http\Controllers\Configuration;

use App\Backups\BackupArchiveException;
use App\Backups\OfflineRestorePreparer;
use App\Backups\PreparedRestore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\PrepareOfflineRestoreRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\InstallationMaintenanceAccessService;
use Illuminate\Http\JsonResponse;
use Throwable;

final class PrepareOfflineRestoreController extends Controller
{
    public function __invoke(
        PrepareOfflineRestoreRequest $request,
        OfflineRestorePreparer $preparer,
        InstallationMaintenanceAccessService $installationMaintenance,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $installationMaintenance->authorize($actor);
        $passphrase = $request->recoveryPassphrase();

        try {
            $prepared = $preparer->prepareUploadedArchive(
                $request->uploadedArchivePath(),
                $passphrase,
            );
            $payload = $this->payload($prepared);
        } catch (Throwable) {
            return response()->json([
                'message' => PrepareOfflineRestoreRequest::failureMessage(),
            ], 422, PrepareOfflineRestoreRequest::responseHeaders());
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($passphrase);
            }

            $request->forgetRecoveryPassphrase();
        }

        AuditLog::record('restore.offline_prepared', metadata: [
            'schema_version' => $payload['backup']['schema_version'],
            'application_version' => $payload['backup']['application_version'],
            'components' => array_column($payload['backup']['components'], 'name'),
            'file_count' => $payload['backup']['file_count'],
            'size_bytes' => $payload['backup']['size_bytes'],
            'active_data_changed' => false,
        ], userId: (int) $actor->getKey());

        return response()->json(
            $payload,
            headers: PrepareOfflineRestoreRequest::responseHeaders(),
        );
    }

    /**
     * @return array{
     *     authorization: array{protocol: string, version: int, operation_id: string, plan_sha256: string},
     *     backup: array{
     *         created_at: string,
     *         application_version: string,
     *         schema_version: int,
     *         components: list<array{name: string, file_count: int, size_bytes: int}>,
     *         file_count: int,
     *         size_bytes: int
     *     }
     * }
     */
    private function payload(PreparedRestore $prepared): array
    {
        $manifest = $prepared->manifest;
        $createdAt = $manifest['created_at'] ?? null;
        $applicationVersion = $manifest['application_version'] ?? null;
        $schemaVersion = $manifest['schema_version'] ?? null;
        $declaredComponents = $manifest['components'] ?? null;

        if (! is_string($createdAt)
            || strlen($createdAt) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $createdAt) === 1
            || ! is_string($applicationVersion)
            || $applicationVersion === ''
            || strlen($applicationVersion) > 128
            || trim($applicationVersion) !== $applicationVersion
            || preg_match('/[\x00-\x1F\x7F]/', $applicationVersion) === 1
            || ! is_int($schemaVersion)
            || ! is_array($declaredComponents)
            || ! array_is_list($declaredComponents)) {
            throw new BackupArchiveException('The prepared backup summary is invalid.');
        }

        $components = [];
        $allowedNames = ['database', 'private_storage', 'public_storage'];

        foreach ($declaredComponents as $component) {
            if (! is_array($component)
                || ! is_string($component['name'] ?? null)
                || ! in_array($component['name'], $allowedNames, true)
                || ! is_int($component['file_count'] ?? null)
                || $component['file_count'] < 0
                || ! is_int($component['size'] ?? null)
                || $component['size'] < 0) {
                throw new BackupArchiveException('The prepared backup component summary is invalid.');
            }

            $components[] = [
                'name' => $component['name'],
                'file_count' => $component['file_count'],
                'size_bytes' => $component['size'],
            ];
        }

        if (array_column($components, 'name') !== $allowedNames
            || array_sum(array_column($components, 'file_count')) !== $prepared->stagedFileCount
            || array_sum(array_column($components, 'size_bytes')) !== $prepared->stagedBytes) {
            throw new BackupArchiveException('The prepared backup totals are invalid.');
        }

        return [
            'authorization' => $prepared->nativeAuthorizationArtifact(),
            'backup' => [
                'created_at' => $createdAt,
                'application_version' => $applicationVersion,
                'schema_version' => $schemaVersion,
                'components' => $components,
                'file_count' => $prepared->stagedFileCount,
                'size_bytes' => $prepared->stagedBytes,
            ],
        ];
    }
}
