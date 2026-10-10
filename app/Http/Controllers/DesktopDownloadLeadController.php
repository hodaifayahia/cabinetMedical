<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Cabinets\CabinetResource;
use App\Http\Requests\StoreDesktopDownloadLeadRequest;
use App\Models\User;
use App\Services\Cabinet\CabinetLeadRegistrar;
use App\Services\DesktopDownloadService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        CabinetLeadRegistrar $registrar,
    ): Response {
        abort_unless($download->hasDownload(), 404, 'L’installateur desktop n’est pas configuré.');

        $lead = $registrar->register($request->safe()->only([
            'name',
            'email',
            'phone',
            'cabinet_name',
            'specialization',
        ]));

        $platformAdmins = User::query()
            ->where('is_platform_admin', true)
            ->get();

        if ($platformAdmins->isNotEmpty()) {
            try {
                Notification::make()
                    ->title('Nouvelle inscription au téléchargement Windows')
                    ->body($lead->name.' a demandé le téléchargement pour « '.$lead->cabinet_name.' ».')
                    ->actions([
                        Action::make('openCabinets')
                            ->label('Ouvrir les cabinets')
                            ->url(CabinetResource::getUrl('index')),
                    ])
                    ->sendToDatabase($platformAdmins, isEventDispatched: true);
            } catch (Throwable $exception) {
                Log::warning('Desktop download lead admin notification could not be sent.', [
                    'lead_id' => $lead->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

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
