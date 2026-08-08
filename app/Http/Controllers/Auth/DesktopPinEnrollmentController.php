<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EnrollDesktopPinRequest;
use App\Models\User;
use App\Services\Auth\DesktopPinService;
use Illuminate\Http\RedirectResponse;

final class DesktopPinEnrollmentController extends Controller
{
    public function __invoke(
        EnrollDesktopPinRequest $request,
        DesktopPinService $desktopPins,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $desktopPins->enroll(
            $user,
            (string) $request->validated('device_token'),
            (string) $request->validated('pin'),
            (string) $request->validated('device_name'),
        );

        return back(303)->with(
            'status',
            'Le code PIN de cet appareil est configuré.',
        );
    }
}
