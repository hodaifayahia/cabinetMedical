<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cabinet\RedeemHostedLicenseCodeRequest;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Illuminate\Http\RedirectResponse;

class RedeemHostedLicenseCodeController extends Controller
{
    public function __invoke(
        RedeemHostedLicenseCodeRequest $request,
        CabinetFulfillmentService $fulfillment,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->cabinet instanceof Cabinet, 403);

        $fulfillment->redeemLicenseCode(
            $user->cabinet,
            $user,
            $request->validated('license_code'),
        );

        return to_route('dashboard')->with(
            'success',
            'Licence activée. Bienvenue dans votre espace DrClickDz.',
        );
    }
}
