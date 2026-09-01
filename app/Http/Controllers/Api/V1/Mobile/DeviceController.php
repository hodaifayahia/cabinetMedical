<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers and releases push-capable devices for the authenticated mobile
 * user (any role). Phase 1 only stores the tokens — push delivery ships in
 * a later phase.
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $values = [
            'user_id' => $user->getKey(),
            'last_seen_at' => now(),
        ];

        if (($validated['platform'] ?? null) !== null) {
            $values['platform'] = $validated['platform'];
        }

        // A token identifies one physical device: re-registering claims it for
        // the current account (e.g. after switching accounts on the phone).
        $device = DevicePushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            $values,
        );

        return response()->json(
            ['message' => 'Appareil enregistré.'],
            $device->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Scoped to the caller: nobody can unregister another account's device.
        DevicePushToken::query()
            ->where('token', $validated['token'])
            ->where('user_id', $user->getKey())
            ->delete();

        return response()->json(['message' => 'Appareil supprimé.']);
    }
}
