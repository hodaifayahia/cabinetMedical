<?php

namespace App\Http\Controllers\Settings;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Profil mis à jour.',
        ]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
            && User::role(RoleName::SUPER_ADMINISTRATOR->value)->count() <= 1) {
            throw ValidationException::withMessages([
                'password' => 'Créez un autre super administrateur avant de supprimer ce compte.',
            ]);
        }

        Auth::logout();
        DB::transaction(function () use ($user): void {
            AuditLog::record('profile.account_deleted', $user, [
                'roles' => $user->getRoleNames()->sort()->values()->all(),
            ], $user->getKey());
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
