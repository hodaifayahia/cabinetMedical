<?php

namespace App\Http\Controllers\Configuration;

use App\Backups\BackupCopyDestination;
use App\Backups\InAppBackupRestorer;
use App\Backups\InAppRestoreException;
use App\Backups\LocalBackupCatalog;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ApplicationSettingService;
use App\Services\Backups\LocalBackupAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Configuration › Sauvegardes: where the copies of the backups go, the
 * backups present on this PC (download one), and restoring a backup over
 * the current data, from an uploaded file or one of the listed archives.
 */
final class LocalBackupArchiveController extends Controller
{
    private const RESTORE_SESSION_KEY = 'backup_restore.operation';

    private const RESTORE_CONFIRMATION = 'RESTAURER';

    public function __construct(private readonly LocalBackupAuthority $localBackups) {}

    /** Save the folder that receives a copy of every backup (empty: none). */
    public function updateDestination(
        Request $request,
        ApplicationSettingService $settings,
        BackupCopyDestination $destination,
    ): RedirectResponse {
        $this->localBackups->authorizeManage($request->user());

        $data = $request->validate([
            'copy_directory' => ['nullable', 'string', 'max:1024'],
            'copy_keep' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'copy_keep.*' => 'Indiquez un nombre de copies entre 1 et 365.',
        ]);
        $directory = trim((string) ($data['copy_directory'] ?? ''));

        if ($directory !== '' && ($problem = $destination->problemWith($directory)) !== null) {
            throw ValidationException::withMessages(['copy_directory' => $problem]);
        }

        $settings->setMany([
            Setting::BACKUP_COPY_DIRECTORY => $directory === '' ? null : $directory,
            Setting::BACKUP_COPY_KEEP => (int) $data['copy_keep'],
        ]);

        if ($directory === '') {
            $settings->setInternal(Setting::BACKUP_COPY_LAST_RESULT, null);
        }

        AuditLog::record('backup.copy_destination_updated', metadata: [
            'configured' => $directory !== '',
            'keep' => (int) $data['copy_keep'],
        ], userId: $request->user()?->getKey());
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $directory === ''
                ? 'Les sauvegardes restent uniquement dans le dossier de Drclick sur ce PC.'
                : 'Dossier enregistré : chaque nouvelle sauvegarde y sera aussi copiée et vérifiée.',
        ]);

        return back();
    }

    /** Check a folder without saving it ("Tester l’emplacement"). */
    public function testDestination(Request $request, BackupCopyDestination $destination): JsonResponse
    {
        $this->localBackups->authorizeManage($request->user());

        $data = $request->validate([
            'copy_directory' => ['required', 'string', 'max:1024'],
        ], [
            'copy_directory.required' => 'Indiquez le dossier à tester.',
        ]);
        $problem = $destination->problemWith($data['copy_directory']);

        return response()->json([
            'ok' => $problem === null,
            'message' => $problem ?? 'Drclick peut écrire dans ce dossier : les copies y seront enregistrées.',
        ], headers: ['Cache-Control' => 'no-store, private, max-age=0']);
    }

    public function download(Request $request, LocalBackupCatalog $catalog): BinaryFileResponse
    {
        $this->localBackups->authorizeManage($request->user());

        $path = $catalog->resolve((string) $request->query('archive', ''));
        abort_if($path === null, 404);

        AuditLog::record('backup.local_downloaded', metadata: [
            'filename' => basename($path),
        ], userId: $request->user()?->getKey());

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.medismart.backup',
            'Cache-Control' => 'no-store, private, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Step 1: authenticate the archive (an uploaded file or one listed on
     * this PC) and show what it holds. Nothing active changes.
     */
    public function prepareRestore(
        Request $request,
        InAppBackupRestorer $restorer,
        LocalBackupCatalog $catalog,
    ): JsonResponse {
        $this->localBackups->authorizeRestore($request->user());
        abort_unless($restorer->available(), 503, (new InAppRestoreException(InAppRestoreException::UNAVAILABLE))->userMessage());

        $maximumKilobytes = max(1, intdiv((int) config('medismart.backups.restore_upload_max_bytes'), 1024));
        $data = $request->validate([
            'backup' => [
                'required_without:archive',
                'file',
                'max:'.$maximumKilobytes,
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile
                        || ! str_ends_with(strtolower($value->getClientOriginalName()), '.msbackup')) {
                        $fail('Choisissez un fichier de sauvegarde Drclick (.msbackup).');
                    }
                },
            ],
            'archive' => ['required_without:backup', 'nullable', 'string', 'max:300'],
            'passphrase' => ['nullable', 'string', 'max:1024'],
        ], [
            'backup.required_without' => 'Choisissez le fichier de sauvegarde à restaurer.',
            'archive.required_without' => 'Choisissez la sauvegarde à restaurer.',
        ]);

        if (isset($data['backup']) && $data['backup'] instanceof UploadedFile) {
            $path = (string) $data['backup']->getRealPath();
            $label = $data['backup']->getClientOriginalName();
        } else {
            $path = $catalog->resolve((string) $data['archive']);

            if ($path === null) {
                throw ValidationException::withMessages([
                    'backup' => 'Cette sauvegarde n’est plus présente sur ce PC.',
                ]);
            }

            $label = basename($path);
        }

        @set_time_limit(0);
        $previous = $request->session()->pull(self::RESTORE_SESSION_KEY);

        if (is_array($previous) && is_string($previous['operation_id'] ?? null)) {
            $restorer->discard($previous['operation_id']);
        }

        try {
            $prepared = $restorer->prepare($path, $data['passphrase'] ?? null);
        } catch (InAppRestoreException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->userMessage()]);
        }

        $request->session()->put(self::RESTORE_SESSION_KEY, [
            'operation_id' => $prepared['operation_id'],
            'user_id' => $request->user()?->getKey(),
        ]);

        return response()->json([
            'operation_id' => $prepared['operation_id'],
            'source' => mb_substr($label, 0, 255),
            'summary' => $prepared['summary'],
            'confirmation' => self::RESTORE_CONFIRMATION,
        ], headers: ['Cache-Control' => 'no-store, private, max-age=0']);
    }

    /**
     * Step 2: replace the current data. A safety backup of the data being
     * replaced is written first; everybody then signs in again.
     */
    public function applyRestore(Request $request, InAppBackupRestorer $restorer): JsonResponse
    {
        $this->localBackups->authorizeRestore($request->user());
        abort_unless($restorer->available(), 503, (new InAppRestoreException(InAppRestoreException::UNAVAILABLE))->userMessage());

        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'confirmed' => ['accepted'],
            'confirmation' => ['required', 'string', 'in:'.self::RESTORE_CONFIRMATION],
        ], [
            'confirmed.accepted' => 'Confirmez que les données actuelles doivent être remplacées.',
            'confirmation.*' => 'Saisissez RESTAURER pour confirmer.',
        ]);
        $pending = $request->session()->get(self::RESTORE_SESSION_KEY);

        if (! is_array($pending)
            || ($pending['operation_id'] ?? null) !== $data['operation_id']
            || ($pending['user_id'] ?? null) !== $request->user()?->getKey()) {
            throw ValidationException::withMessages([
                'backup' => (new InAppRestoreException(InAppRestoreException::EXPIRED))->userMessage(),
            ]);
        }

        @set_time_limit(0);

        try {
            $result = $restorer->apply($data['operation_id'], $request->user());
        } catch (InAppRestoreException $exception) {
            $request->session()->forget(self::RESTORE_SESSION_KEY);

            throw ValidationException::withMessages(['backup' => $exception->userMessage()]);
        }

        // The accounts and their sessions now come from the backup.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $summary = $result['summary'];
        $patients = (int) ($summary['patients'] ?? 0);
        $status = 'Sauvegarde restaurée'
            .(is_string($summary['cabinet'] ?? null) ? ' pour « '.$summary['cabinet'].' »' : '')
            .' ('.$patients.' patient'.($patients === 1 ? '' : 's').'). Les données remplacées ont été conservées dans '
            .$result['safety_backup'].'. Connectez-vous de nouveau.';
        $request->session()->flash('status', $status);

        return response()->json([
            'redirect' => route('login'),
            'message' => $status,
        ], headers: ['Cache-Control' => 'no-store, private, max-age=0']);
    }

    /** Forget a verified archive the doctor decided not to restore. */
    public function cancelRestore(Request $request, InAppBackupRestorer $restorer): JsonResponse
    {
        $this->localBackups->authorizeRestore($request->user());

        $pending = $request->session()->pull(self::RESTORE_SESSION_KEY);

        if (is_array($pending) && is_string($pending['operation_id'] ?? null)) {
            $restorer->discard($pending['operation_id']);
        }

        return response()->json(['cancelled' => true]);
    }
}
