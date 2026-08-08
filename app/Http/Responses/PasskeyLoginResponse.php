<?php

namespace App\Http\Responses;

use App\Support\PostLoginDestination;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request)
    {
        $redirect = redirect()->to(PostLoginDestination::for($request->user()));

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect->getTargetUrl(),
            ]);
        }

        return $redirect;
    }
}
