<?php

namespace App\Http\Controllers\Configuration;

use App\Backups\AutomaticBackupCreator;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\MachineFingerprintService;
use App\Updates\UpdateInstallAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class PrepareUpdateInstallController extends Controller
{
    public function __invoke(
        Request $request,
        AutomaticBackupCreator $backups,
        MachineFingerprintService $machine,
        UpdateInstallAuthorization $authorization,
    ): JsonResponse {
        abort_unless(
            (bool) config('medismart.runtime.desktop_supervised', false)
                && (bool) config('medismart.updates.signed_updater_configured', false),
            409,
            'Le programme de mise à jour signé n’est pas disponible dans ce runtime.',
        );

        $data = $request->validate([
            'target_version' => [
                'required',
                'string',
                'max:64',
                'regex:/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/',
            ],
        ]);

        $backup = $backups->create();
        $sha256 = $backup->sha256;

        if ($backup->status !== 'completed'
            || $backup->completed_at === null
            || ! is_string($sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('The pre-update safety backup was not completed and verified.');
        }

        if ($backup->created_by === null && $request->user() !== null) {
            $backup->forceFill(['created_by' => $request->user()->getAuthIdentifier()])->save();
        }

        $artifact = $authorization->issue(
            targetVersion: $data['target_version'],
            backupRecordId: (string) $backup->getKey(),
            backupSha256: $sha256,
            installationId: $machine->installationId(),
        );

        AuditLog::record('updates.install_prepared', $backup, [
            'target_version' => $data['target_version'],
            'backup_record_id' => $backup->getKey(),
            'backup_sha256' => $sha256,
            'authorization_expires_at' => $artifact['expires_at'],
        ], $request->user()?->getAuthIdentifier());

        return response()->json([
            'authorization' => $artifact,
            'backup' => [
                'id' => $backup->getKey(),
                'filename' => $backup->filename,
                'sha256_hint' => substr($sha256, 0, 12).'…',
                'completed_at' => $backup->completed_at->toIso8601String(),
            ],
        ]);
    }
}
