<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class RecoveryCodeController extends Controller
{
    public function store(Request $request, AccountRecoveryService $recovery): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Shown once, on the next page only; only keyed hashes are stored.
        Inertia::flash('recoveryCodes', $recovery->generateCodes($user));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Nouveaux codes de secours créés. Imprimez-les ou enregistrez-les maintenant.',
        ]);

        return to_route('security.edit');
    }
}
