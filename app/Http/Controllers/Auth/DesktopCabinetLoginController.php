<?php

namespace App\Http\Controllers\Auth;

use App\CabinetTransfer\TransferState;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesktopCabinetLoginRequest;
use App\Jobs\ImportCabinetFromOnlineService;
use App\Licensing\DesktopLicenseActivator;
use App\Models\Cabinet;
use App\Models\User;
use App\Support\PostLoginDestination;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;

class DesktopCabinetLoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/DesktopCabinetLogin');
    }

    public function store(
        DesktopCabinetLoginRequest $request,
        StatefulGuard $guard,
        DesktopLicenseActivator $activator,
    ): RedirectResponse {
        $credentials = $request->safe()->only(['email', 'password']);
        $provider = $guard->getProvider();
        $matchingUsers = User::query()
            ->whereRaw('LOWER(email) = ?', [$credentials['email']])
            ->limit(2)
            ->get();
        $user = $matchingUsers->count() === 1 ? $matchingUsers->first() : null;

        // A fresh desktop knows no one yet. The owner of a cabinet that
        // already exists online brings it here with that online account:
        // the cabinet (identity only) is recreated on this poste, activated
        // with a signed licence and linked for mobile appointments. Needs
        // Internet this once; the poste then works offline.
        // The same owner may also bring the records the cabinet kept online:
        // only into an empty PC, copied in the background (progress page),
        // then removed online once verified here.
        if ($request->boolean('import_records')
            && $credentials['email'] === $request->string('owner_email')->toString()
            && $activator->canActivateOnline()
            && ! Cabinet::query()->exists()
            && ! User::query()->exists()) {
            $activation = $activator->activateForTransfer($credentials['email'], $credentials['password']);

            TransferState::begin(
                $activation->endpoint,
                (string) $activation->token,
                $activation->envelope,
                $activation->cabinet,
                $activation->accountEmail ?? $activation->entitlement->ownerEmail,
                $activation->accountCabinetName,
            );
            ImportCabinetFromOnlineService::dispatch();

            return redirect()->route('desktop.transfer.show');
        }

        if ($matchingUsers->isEmpty()
            && $credentials['email'] === $request->string('owner_email')->toString()
            && $activator->canActivateOnline()) {
            $owner = $activator->importOnlineCabinet($credentials['email'], $credentials['password']);

            $guard->login($owner, $request->boolean('remember'));
            $request->session()->regenerate();

            return redirect()->to(PostLoginDestination::for($owner))->with(
                'success',
                'Cabinet importé depuis votre compte en ligne. Ce poste fonctionne désormais sans Internet.',
            );
        }

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            $this->fail($request, $user instanceof User ? $user : null);
        }

        $belongsToNamedCabinet = ! $user->is_platform_admin
            && $user->approved_at !== null
            && $user->cabinet_id !== null
            && Cabinet::query()
                ->whereKey($user->cabinet_id)
                ->whereHas('owner', fn ($query) => $query->whereRaw(
                    'LOWER(email) = ?',
                    [$request->string('owner_email')->toString()],
                ))
                ->exists();

        if (! $belongsToNamedCabinet) {
            $this->fail($request, $user);
        }

        if (config('hashing.rehash_on_login', true) && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }

        if ($this->requiresTwoFactorChallenge($user)) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $request->boolean('remember'),
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        $guard->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->to(PostLoginDestination::for($user));
    }

    private function requiresTwoFactorChallenge(User $user): bool
    {
        if (! Features::enabled(Features::twoFactorAuthentication())
            || ! $user->two_factor_secret
            || ! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        return ! Fortify::confirmsTwoFactorAuthentication()
            || $user->two_factor_confirmed_at !== null;
    }

    private function fail(
        DesktopCabinetLoginRequest $request,
        ?User $user,
    ): never {
        event(new Failed((string) config('fortify.guard', 'web'), $user, [
            'email' => $request->string('email')->toString(),
            'password' => $request->input('password'),
        ]));

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }
}
