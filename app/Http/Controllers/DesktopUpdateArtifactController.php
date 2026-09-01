<?php

namespace App\Http\Controllers;

use App\Models\DesktopRelease;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the installer named by the update manifest.
 *
 * Public for the same reason the manifest is: the updater carries no session.
 * Integrity does not rest on this route being private, it rests on the detached
 * signature, which the shell checks before it installs anything. Serving a
 * corrupted or swapped file here produces a refused update, not a bad install.
 */
final class DesktopUpdateArtifactController extends Controller
{
    public function __invoke(DesktopRelease $release): BinaryFileResponse
    {
        abort_unless($release->isPublished(), 404);

        $path = realpath($release->installerFullPath());
        $base = realpath(storage_path('app/private/desktop/releases'));

        abort_if($path === false || $base === false, 404, 'Cette version n’est plus disponible.');
        abort_unless(str_starts_with($path, $base.DIRECTORY_SEPARATOR), 404);
        abort_unless(is_file($path) && is_readable($path), 404);

        return response()->download($path, $release->installer_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
