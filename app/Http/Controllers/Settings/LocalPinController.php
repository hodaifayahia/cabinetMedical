<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LocalPinRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

final class LocalPinController extends Controller
{
    public function store(LocalPinRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $wasConfigured = $user->local_pin_hash !== null;

        DB::transaction(function () use ($request, $user, $wasConfigured): void {
            $user->forceFill([
                'local_pin_hash' => Hash::make($request->validated('pin')),
            ])->save();

            AuditLog::record(
                $wasConfigured ? 'security.local_pin_changed' : 'security.local_pin_set',
                $user,
                ['state' => $wasConfigured ? 'changed' : 'configured'],
            );
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasConfigured ? 'Code PIN modifié.' : 'Code PIN configuré.',
        ]);

        return to_route('security.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->local_pin_hash !== null) {
            DB::transaction(function () use ($user): void {
                $user->forceFill(['local_pin_hash' => null])->save();
                AuditLog::record('security.local_pin_removed', $user, [
                    'state' => 'removed',
                ]);
            });
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Code PIN supprimé.',
        ]);

        return to_route('security.edit');
    }
}
