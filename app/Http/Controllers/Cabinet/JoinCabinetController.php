<?php

namespace App\Http\Controllers\Cabinet;

use App\Actions\Cabinet\JoinCabinetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinCabinetRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class JoinCabinetController extends Controller
{
    /**
     * Public form used by a prospective staff member to request access to an
     * existing cabinet by naming its owner's e-mail address.
     */
    public function create(): Response
    {
        return Inertia::render('auth/JoinCabinet');
    }

    public function store(JoinCabinetRequest $request, JoinCabinetAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return redirect()->route('login')->with(
            'status',
            'Votre demande a été envoyée. Vous pourrez vous connecter une fois approuvé par le propriétaire du cabinet.',
        );
    }
}
