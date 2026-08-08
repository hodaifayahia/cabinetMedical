<?php

namespace App\Http\Responses;

use App\Support\PostLoginDestination;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $destination = PostLoginDestination::for($request->user());

        if ($destination === '/admin' && $request->header('X-Inertia')) {
            return Inertia::location($destination);
        }

        return redirect()->to($destination);
    }
}
