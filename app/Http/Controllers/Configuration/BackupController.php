<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\User;
use App\Services\ApplicationHealthService;
use App\Services\BackupService;
use App\Services\GoogleDriveService;
use App\Services\InstallationMaintenanceAccessService;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    private const LOCAL_BACKUP_FAILED = 'The local backup could not be created. Please try again.';

    private const LOCAL_RESTORE_FAILED = 'The backup could not be restored. Please contact support before trying again.';

    public function __construct(
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
    ) {}

    public function local(Request $request, BackupService $backup): BinaryFileResponse|RedirectResponse
    {
        $this->installationMaintenance->authorize($request->user());

        try {
            $file = $backup->createArchive($request->user());

            return response()->download($file['path'], $file['filename'], [
                'Content-Type' => 'application/vnd.medismart.backup+zip',
            ]);
        } catch (Throwable) {
            return back()->withErrors(['backup' => self::LOCAL_BACKUP_FAILED]);
        }
    }

    public function encryptedLocal(
        Request $request,
        BackupService $backup,
    ): BinaryFileResponse|RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        $data = $request->validate([
            'passphrase' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        try {
            $file = $backup->createEncryptedArchive(
                $data['passphrase'],
                $request->user(),
            );

            return response()->download($file['path'], $file['filename'], [
                'Content-Type' => 'application/vnd.medismart.backup',
                'Cache-Control' => 'no-store, private, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable) {
            return back()->withErrors(['encrypted_backup' => self::LOCAL_BACKUP_FAILED]);
        }
    }

    public function prepareGoogleOAuth(
        Request $request,
        GoogleDriveService $drive,
    ): JsonResponse {
        $this->installationMaintenance->authorize($request->user());

        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        if (! $drive->isConfigured()) {
            return response()->json([
                'message' => 'Impossible de preparer la connexion Google Drive.',
            ], 503, $this->oauthResponseHeaders());
        }

        try {
            $prepared = $drive->prepareAuthorization(CabinetSetting::current(), $actor);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Impossible de preparer la connexion Google Drive.',
            ], 503, $this->oauthResponseHeaders());
        }

        return response()->json($prepared, headers: $this->oauthResponseHeaders());
    }

    public function restore(Request $request, BackupService $backup): RedirectResponse
    {
        $this->installationMaintenance->authorize($request->user());

        abort_unless((bool) config('medismart.backups.legacy_restore_enabled'), 404);

        /** @var UploadedFile $file */
        $file = $request->validate([
            'backup' => ['required', 'file', 'max:2097152'],
        ])['backup'];

        try {
            $backup->restoreLegacySnapshot($file, $request->user());
        } catch (Throwable) {
            return back()->withErrors(['backup' => self::LOCAL_RESTORE_FAILED]);
        }

        return back()->with('status', 'Local backup restored.');
    }

    public function googleCallback(Request $request, GoogleDriveService $drive): Response
    {
        $success = false;

        try {
            $drive->completeAuthorization($request);
            $success = true;
        } catch (Throwable) {
            // Never reflect OAuth values or exception details into the system browser.
        }

        return response()->view(
            'oauth.google-drive-callback',
            ['success' => $success],
            $success ? 200 : 400,
            $this->oauthResponseHeaders(),
        );
    }

    public function disconnectDrive(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $this->installationMaintenance->authorize($request->user());

        $cabinet = CabinetSetting::current();
        $revocationConfirmed = $drive->disconnect($cabinet);
        AuditLog::record('backup.drive_disconnected', $cabinet, [
            'provider' => 'google_drive',
            'remote_revocation_confirmed' => $revocationConfirmed,
        ], $request->user()?->getKey());
        ApplicationEvent::record('CloudDriveDisconnected', context: [
            'provider' => 'google_drive',
            'remote_revocation_confirmed' => $revocationConfirmed,
        ]);

        return back()->with(
            'status',
            $revocationConfirmed
                ? 'Le compte Google Drive a été déconnecté.'
                : 'Les identifiants locaux ont été supprimés. La révocation distante n’a pas pu être confirmée.',
        );
    }

    public function testDriveConnection(
        Request $request,
        GoogleDriveService $drive,
        LicenseService $licenses,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        abort_unless($drive->isConfigured(), 503, 'Google Drive is not configured on this installation.');
        abort_unless($licenses->featureEnabled('google_drive_backup'), 403);

        try {
            $drive->testConnection(CabinetSetting::current());
            AuditLog::record('backup.drive_connection_verified', CabinetSetting::current(), [
                'provider' => 'google_drive',
            ], $request->user()?->getKey());
            ApplicationEvent::record('CloudDriveConnectionVerified', context: [
                'provider' => 'google_drive',
            ]);
        } catch (Throwable) {
            return back()->withErrors([
                'drive_connection' => 'La connexion Google Drive n’a pas pu être vérifiée. Reconnectez le compte si le problème persiste.',
            ]);
        }

        return back()->with('status', 'Connexion Google Drive vérifiée.');
    }

    public function driveFiles(
        Request $request,
        GoogleDriveService $drive,
        LicenseService $licenses,
    ): JsonResponse {
        $this->installationMaintenance->authorize($request->user());

        abort_unless($drive->isConfigured(), 503, 'Google Drive is not configured on this installation.');
        abort_unless($licenses->featureEnabled('google_drive_backup'), 403);

        try {
            $backups = $drive->listBackups(CabinetSetting::current());
        } catch (Throwable) {
            return response()->json([
                'message' => 'La liste des sauvegardes Drive n’a pas pu être chargée.',
            ], 503);
        }

        return response()->json(['backups' => $backups], headers: [
            'Cache-Control' => 'no-store, private, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadDriveFile(
        Request $request,
        string $fileId,
        GoogleDriveService $drive,
        LicenseService $licenses,
    ): BinaryFileResponse|RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        abort_unless($drive->isConfigured(), 503, 'Google Drive is not configured on this installation.');
        abort_unless($licenses->featureEnabled('google_drive_backup'), 403);

        try {
            $record = $drive->downloadVerifiedArchive(
                CabinetSetting::current(),
                $fileId,
                $request->user(),
            );
            $path = $record->local_path;

            if (! is_string($path) || ! is_file($path) || is_link($path)) {
                throw new \RuntimeException('The verified local Drive backup is unavailable.');
            }

            return response()->download($path, $record->filename, [
                'Content-Type' => 'application/vnd.medismart.backup',
                'Cache-Control' => 'no-store, private, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable) {
            return back()->withErrors([
                'drive_download' => 'L’archive Drive n’a pas pu être téléchargée et vérifiée. Aucun fichier non vérifié n’a été conservé.',
            ]);
        }
    }

    public function deleteDriveFile(
        Request $request,
        string $fileId,
        GoogleDriveService $drive,
        LicenseService $licenses,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        abort_unless($drive->isConfigured(), 503, 'Google Drive is not configured on this installation.');
        abort_unless($licenses->featureEnabled('google_drive_backup'), 403);

        try {
            $deleted = $drive->deleteManagedBackup(CabinetSetting::current(), $fileId);
            AuditLog::record('backup.drive_deleted', CabinetSetting::current(), [
                'provider' => 'google_drive',
                'remote_file_id_hash' => substr(hash('sha256', $deleted['id']), 0, 16),
                'source_backup_record_id' => $deleted['backup_record_id'],
                'newer_backup_record_id' => $deleted['newer_backup_record_id'],
                'filename' => $deleted['name'],
            ], $request->user()?->getKey());
            ApplicationEvent::record('BackupDriveDeleted', context: [
                'source_backup_record_id' => $deleted['backup_record_id'],
                'newer_backup_record_id' => $deleted['newer_backup_record_id'],
            ]);
        } catch (Throwable) {
            return back()->withErrors([
                'drive_delete' => 'La suppression a été refusée ou le fichier n’est plus une archive DrClickDz gérée.',
            ]);
        }

        return back()->with('status', 'Archive DrClickDz supprimée de Google Drive.');
    }

    /** @return array<string, string> */
    private function oauthResponseHeaders(): array
    {
        $nonce = Vite::cspNonce();

        if (! is_string($nonce) || $nonce === '') {
            $nonce = Vite::useCspNonce();
        }

        return [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "base-uri 'none'",
                "connect-src 'none'",
                "form-action 'none'",
                "frame-ancestors 'none'",
                "frame-src 'none'",
                "font-src 'none'",
                "img-src 'none'",
                "manifest-src 'none'",
                "media-src 'none'",
                "object-src 'none'",
                "script-src 'none'",
                "script-src-attr 'none'",
                "style-src 'nonce-{$nonce}'",
                "style-src-attr 'none'",
                "worker-src 'none'",
            ]),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }

    public function storeDrive(
        Request $request,
        BackupService $backup,
        ApplicationHealthService $health,
        GoogleDriveService $drive,
        LicenseService $licenses,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        abort_unless($drive->isConfigured(), 503, 'Google Drive is not configured on this installation.');
        abort_unless($licenses->featureEnabled('google_drive_backup'), 403, 'The active license does not include Google Drive backups.');

        $runtimeStatus = $health->status();
        abort_unless(
            ($runtimeStatus['queue']['worker_status'] ?? null) === 'active'
                && ($runtimeStatus['queue']['operational'] ?? false) === true
                && ($runtimeStatus['queue']['observation_source'] ?? null)
                    === 'native_supervisor_process_contract',
            503,
            'The supervised backup worker is not active.',
        );

        $cabinet = CabinetSetting::current();
        $driveStatus = $drive->status($cabinet);
        abort_unless(
            ($driveStatus['google_drive_connected'] ?? false) === true,
            409,
            'Connect a Google Drive account before saving a backup.',
        );

        $data = $request->validate([
            'folder_name' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[^\\x00-\\x1F\\x7F]+$/u'],
            'passphrase' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        $record = null;

        try {
            $archive = $backup->createEncryptedArchive(
                $data['passphrase'],
                $request->user(),
            );
            $record = $archive['record'];
            $record->forceFill([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_QUEUED,
                'drive_upload_bytes' => 0,
                'drive_upload_attempts' => 0,
                'drive_upload_failure_code' => null,
                'drive_upload_cancel_requested_at' => null,
                'drive_upload_updated_at' => now(),
            ])->save();
            UploadBackupToGoogleDrive::dispatch(
                (int) $cabinet->getKey(),
                (string) $record->getKey(),
                trim($data['folder_name']),
            );
        } catch (Throwable) {
            if ($record instanceof BackupRecord
                && $record->drive_upload_status === BackupRecord::DRIVE_UPLOAD_QUEUED) {
                $record->forceFill([
                    'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_FAILED,
                    'drive_upload_failure_code' => 'queue_dispatch_failed',
                    'drive_upload_updated_at' => now(),
                ])->save();
            }

            return back()->withErrors([
                'drive_backup' => 'La sauvegarde chiffrée n’a pas pu être préparée pour Google Drive.',
            ]);
        }

        return back()->with('status', 'La sauvegarde chiffrée a été ajoutée à la file d’envoi Google Drive.');
    }

    public function cancelDriveUpload(
        Request $request,
        string $backupRecordId,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        $backupRecord = BackupRecord::query()->findOrFail($backupRecordId);

        if ($backupRecord->drive_upload_status === BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED
            || $backupRecord->drive_upload_status === BackupRecord::DRIVE_UPLOAD_CANCELLED) {
            return back()->with('status', 'L’annulation de l’envoi Google Drive est déjà enregistrée.');
        }

        $updated = BackupRecord::query()
            ->whereKey($backupRecord->getKey())
            ->whereNull('remote_file_id')
            ->whereIn('drive_upload_status', [
                BackupRecord::DRIVE_UPLOAD_QUEUED,
                BackupRecord::DRIVE_UPLOAD_UPLOADING,
                BackupRecord::DRIVE_UPLOAD_RETRYING,
            ])
            ->update([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                'drive_upload_cancel_requested_at' => now(),
                'drive_upload_updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return back()->withErrors([
                'drive_cancel' => 'Cet envoi Google Drive est déjà terminé et ne peut plus être annulé.',
            ]);
        }

        AuditLog::record('backup.drive_upload_cancel_requested', $backupRecord, [
            'provider' => 'google_drive',
            'format' => 'msbackup',
            'format_version' => 2,
        ], $request->user()?->getKey());
        ApplicationEvent::record('BackupDriveUploadCancellationRequested', context: [
            'backup_record_id' => $backupRecord->getKey(),
            'provider' => 'google_drive',
        ]);

        return back()->with('status', 'L’annulation de l’envoi Google Drive a été demandée.');
    }
}
