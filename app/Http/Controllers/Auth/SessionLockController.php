<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SessionLockController extends Controller
{
    private const INVALID_CREDENTIAL_MESSAGE = 'Les informations fournies sont invalides.';

    public function show(Request $request, SessionLockService $sessionLock): Response|RedirectResponse
    {
        $sessionLock->synchronizeUser($request);

        if (! $sessionLock->isLocked($request)) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/LockScreen', [
            'pinConfigured' => $request->user()?->local_pin_hash !== null,
        ]);
    }

    public function lock(Request $request, SessionLockService $sessionLock): RedirectResponse|SymfonyResponse
    {
        if (! $sessionLock->matchesCurrentInstance($request, $request->input('session_instance_id'))) {
            return $this->staleInstanceResponse($request);
        }

        $sessionLock->lock(
            $request,
            'manual',
            is_string($request->input('intended')) ? $request->input('intended') : null,
        );

        return to_route('session-lock.show');
    }

    public function lockIdle(Request $request, SessionLockService $sessionLock): RedirectResponse|SymfonyResponse
    {
        if (! $sessionLock->matchesCurrentInstance($request, $request->input('session_instance_id'))) {
            return $this->staleInstanceResponse($request);
        }

        $sessionLock->lock(
            $request,
            'idle',
            is_string($request->input('intended')) ? $request->input('intended') : null,
        );

        return to_route('session-lock.show');
    }

    public function activity(Request $request, SessionLockService $sessionLock): HttpResponse
    {
        if (! $sessionLock->matchesCurrentInstance(
            $request,
            $request->header('X-MediSmart-Session-Instance'),
        )) {
            return response()->noContent(409, [
                'Cache-Control' => 'no-store, private, max-age=0',
            ]);
        }

        $sessionLock->touch($request);

        return response()->noContent(204, [
            'Cache-Control' => 'no-store, private, max-age=0',
        ]);
    }

    public function unlock(Request $request, SessionLockService $sessionLock): RedirectResponse
    {
        $sessionLock->synchronizeUser($request);

        if (! $sessionLock->isLocked($request)) {
            return to_route('dashboard');
        }

        /** @var User $user */
        $user = $request->user();
        $method = $request->input('method');
        $valid = false;
        $auditMethod = 'unsupported';

        if ($method === 'pin') {
            $auditMethod = 'quick_code';
            $pin = $request->input('pin');

            $valid = is_string($pin)
                && preg_match('/\A[0-9]{6,12}\z/D', $pin) === 1
                && is_string($user->local_pin_hash)
                && Hash::check($pin, $user->local_pin_hash);

            if ($valid && Hash::needsRehash($user->local_pin_hash)) {
                $user->forceFill(['local_pin_hash' => Hash::make($pin)])->save();
            }
        } elseif ($method === 'password') {
            $auditMethod = 'full_password';
            $password = $request->input('password');
            $valid = is_string($password)
                && strlen($password) <= 4096
                && Hash::check($password, $user->password);
        }

        if (! $valid) {
            AuditLog::record('security.session_unlock_failed', $user, [
                'method' => $auditMethod,
            ]);

            return to_route('session-lock.show')
                ->withErrors(['credential' => self::INVALID_CREDENTIAL_MESSAGE])
                ->withInput(['method' => in_array($method, ['pin', 'password'], true) ? $method : 'pin']);
        }

        $intended = $sessionLock->unlock($request, $auditMethod);

        return redirect()->to($intended);
    }

    private function staleInstanceResponse(Request $request): SymfonyResponse
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('session-lock.show'));
        }

        return response()->noContent(409, [
            'Cache-Control' => 'no-store, private, max-age=0',
        ]);
    }
}
