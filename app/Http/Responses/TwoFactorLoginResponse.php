<?php

namespace App\Http\Responses;

use App\Support\PostLoginDestination;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $destination = PostLoginDestination::for($request->user());

        if ($destination === '/admin' && $request->header('X-Inertia')) {
            return Inertia::location($destination);
        }

        return redirect()->to($destination);
    }
}
