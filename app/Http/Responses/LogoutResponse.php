<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        // The installed desktop client keeps its device-bound PIN enrollment
        // after logout, so send it back to the PIN form instead of the public
        // marketing page. Browser logout keeps Fortify's existing behavior.
        return redirect()->to($request->boolean('desktop') ? route('login') : route('home'));
    }
}
