<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginWithDesktopPinRequest;
use App\Services\Auth\DesktopPinService;
use App\Support\PostLoginDestination;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;

final class DesktopPinLoginController extends Controller
{
    public function __invoke(
        LoginWithDesktopPinRequest $request,
        DesktopPinService $desktopPins,
        StatefulGuard $guard,
    ): RedirectResponse {
        $user = $desktopPins->authenticate(
            (string) $request->validated('device_token'),
            (string) $request->validated('pin'),
        );

        $guard->login($user, false);
        $request->session()->regenerate();
        $request->session()->forget('auth.password_confirmed_at');

        return redirect()->to(PostLoginDestination::for($user));
    }
}
