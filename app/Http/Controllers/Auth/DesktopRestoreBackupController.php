<?php

namespace App\Http\Controllers\Auth;

use App\Backups\FirstRunBackupImporter;
use App\Backups\FirstRunImportException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Start from a backup" on a new desktop, before any account exists: the
 * doctor picks a .msbackup (from a USB key, the old PC or Google Drive) and
 * the clinic comes back as it was. Once anyone has registered, the page is
 * gone and only the regular sign-in remains.
 */
class DesktopRestoreBackupController extends Controller
{
    public function create(FirstRunBackupImporter $importer): Response|RedirectResponse
    {
        if (! $importer->available()) {
            return to_route('login');
        }

        return Inertia::render('auth/DesktopRestoreBackup', [
            'maximumBytes' => (int) config('medismart.backups.restore_upload_max_bytes'),
        ]);
    }

    public function store(Request $request, FirstRunBackupImporter $importer): RedirectResponse
    {
        if (! $importer->available()) {
            return to_route('login');
        }

        $maximumKilobytes = max(1, intdiv((int) config('medismart.backups.restore_upload_max_bytes'), 1024));
        $data = $request->validate([
            'backup' => [
                'required',
                'file',
                'max:'.$maximumKilobytes,
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile
                        || ! str_ends_with(strtolower($value->getClientOriginalName()), '.msbackup')) {
                        $fail('Choisissez un fichier de sauvegarde Drclick (.msbackup).');
                    }
                },
            ],
            'passphrase' => ['nullable', 'string', 'max:1024'],
            'confirmed' => ['accepted'],
        ], [
            'backup.required' => 'Choisissez le fichier de sauvegarde à restaurer.',
            'confirmed.accepted' => 'Confirmez que ce PC doit repartir de cette sauvegarde.',
        ]);

        // Large clinics take longer than the interpreter's default request
        // limit to verify and copy, and stopping half way helps nobody.
        @set_time_limit(0);

        try {
            $summary = $importer->import(
                $data['backup']->getRealPath(),
                $data['passphrase'] ?? null,
            );
        } catch (FirstRunImportException $exception) {
            return back()->withErrors([
                $exception->reason === FirstRunImportException::PASSPHRASE_REQUIRED
                    || $exception->reason === FirstRunImportException::DECRYPTION_FAILED
                    ? 'passphrase'
                    : 'backup' => $exception->userMessage(),
            ]);
        }

        $patients = $summary['patients'].' patient'.($summary['patients'] === 1 ? '' : 's');

        return to_route('login')->with(
            'status',
            'Sauvegarde restaurée'.($summary['cabinet'] !== null ? ' pour « '.$summary['cabinet'].' »' : '')
                .' ('.$patients.'). Connectez-vous avec votre compte habituel.',
        );
    }
}
