<?php

namespace App\Http\Controllers;

use App\Services\DesktopDownloadService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DesktopDownloadController extends Controller
{
    public function __invoke(DesktopDownloadService $download): BinaryFileResponse|RedirectResponse
    {
        $localInstaller = $download->localInstallerPath();

        if ($localInstaller !== null) {
            return response()->download($localInstaller, basename($localInstaller), [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $externalUrl = $download->externalUrl();

        abort_if($externalUrl === null, 404, 'L’installateur desktop n’est pas configuré.');

        return redirect()->away($externalUrl);
    }
}
