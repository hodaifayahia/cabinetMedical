<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\FortifyServiceProvider;
use App\Services\Auth\AccountRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mot de passe ou PIN oublié" without e-mail: a printed recovery code, the
 * poste principal key file, or a manager of the cabinet.
 */
final class AccountRecoveryController extends Controller
{
    public function __construct(private readonly AccountRecoveryService $recovery) {}

    public function show(Request $request): Response
    {
        return Inertia::render('auth/AccountRecovery', [
            'deviceRecoveryAvailable' => $this->recovery->deviceRecoveryAvailable($request),
            'emailResetAvailable' => FortifyServiceProvider::passwordResetDeliveryConfigured(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function resetWithCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'code' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $this->recovery->resetWithCode($data['email'], $data['code'], $data['password']);

        return to_route('login')->with(
            'status',
            'Mot de passe réinitialisé. Connectez-vous avec le nouveau mot de passe, puis créez un nouveau code PIN. Pensez à générer de nouveaux codes de secours.',
        );
    }

    public function issueDeviceCode(Request $request): JsonResponse
    {
        abort_unless($this->recovery->deviceRecoveryAvailable($request), 404);

        return response()->json([
            'path' => $this->recovery->issueDeviceCode(),
            'expires_in_minutes' => AccountRecoveryService::DEVICE_CODE_TTL_MINUTES,
        ]);
    }

    public function resetWithDeviceCode(Request $request): RedirectResponse
    {
        abort_unless($this->recovery->deviceRecoveryAvailable($request), 404);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'code' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $this->recovery->resetWithDeviceCode($data['email'], $data['code'], $data['password']);

        return to_route('login')->with(
            'status',
            'Mot de passe réinitialisé. Connectez-vous avec le nouveau mot de passe, puis créez un nouveau code PIN.',
        );
    }
}
