<?php

namespace App\Http\Controllers;

use App\Models\DesktopDownloadLead;
use App\Services\DesktopDownloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DesktopDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        DesktopDownloadLead $lead,
        DesktopDownloadService $download,
    ): BinaryFileResponse|RedirectResponse {
        $authorizedUntil = $request->session()->get(
            "desktop_download.authorized.{$lead->getKey()}",
        );

        abort_unless(
            is_int($authorizedUntil) && $authorizedUntil >= now()->getTimestamp(),
            403,
        );

        $localInstaller = $download->localInstallerPath();

        if ($localInstaller !== null) {
            $lead->forceFill(['downloaded_at' => now()])->saveQuietly();

            return response()->download($localInstaller, basename($localInstaller), [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $externalUrl = $download->externalUrl();

        abort_if($externalUrl === null, 404, 'L’installateur desktop n’est pas configuré.');

        $lead->forceFill(['downloaded_at' => now()])->saveQuietly();

        return redirect()->away($externalUrl);
    }
}
