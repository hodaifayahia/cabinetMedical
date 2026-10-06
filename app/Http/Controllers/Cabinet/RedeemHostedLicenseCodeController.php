<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cabinet\RedeemHostedLicenseCodeRequest;
use App\Licensing\DesktopLicenseActivator;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class RedeemHostedLicenseCodeController extends Controller
{
    public function __invoke(
        RedeemHostedLicenseCodeRequest $request,
        DesktopLicenseActivator $activator,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->cabinet instanceof Cabinet, 403);

        // Tried locally first; an installed desktop then redeems the code on
        // the online service once and keeps the signed licence it receives.
        $activator->redeemCode(
            $user->cabinet,
            $user,
            $request->validated('license_code'),
        );

        return to_route('dashboard')->with(
            'success',
            'Licence activée. Bienvenue dans votre espace Drclick.',
        );
    }
}
