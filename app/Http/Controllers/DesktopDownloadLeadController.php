<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDesktopDownloadLeadRequest;
use App\Models\DesktopDownloadLead;
use App\Services\DesktopDownloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DesktopDownloadLeadController extends Controller
{
    public function show(DesktopDownloadService $download): RedirectResponse
    {
        abort_unless($download->hasDownload(), 404, 'L’installateur desktop n’est pas configuré.');

        return redirect()->to(route('home', ['download' => 1]).'#telecharger');
    }

    public function store(
        StoreDesktopDownloadLeadRequest $request,
        DesktopDownloadService $download,
    ): Response {
        abort_unless($download->hasDownload(), 404, 'L’installateur desktop n’est pas configuré.');

        $lead = DesktopDownloadLead::query()->create(
            $request->safe()->only([
                'name',
                'email',
                'phone',
                'cabinet_name',
                'specialization',
            ]),
        );
        $downloadUrl = URL::temporarySignedRoute(
            'desktop.download.file',
            now()->addMinutes(10),
            ['lead' => $lead],
        );
        $request->session()->put(
            "desktop_download.authorized.{$lead->getKey()}",
            now()->addMinutes(10)->getTimestamp(),
        );

        if ($request->header('X-Inertia')) {
            return Inertia::location($downloadUrl);
        }

        return redirect()->to($downloadUrl);
    }
}
