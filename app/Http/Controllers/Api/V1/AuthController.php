<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * Issue a personal access token for a valid, eligible account.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Ces identifiants ne correspondent à aucun compte.'],
            ]);
        }

        // Block pending/suspended cabinets and unapproved members with 403.
        $reason = $this->access->denialReason($user);
        if ($reason !== null) {
            return response()->json([
                'message' => $this->access->denialMessage($user),
                'reason' => $reason,
                'status' => $this->access->denialStatus($user),
            ], 403);
        }

        $token = $user->createToken($data['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => (new UserResource($user->load('cabinet.license')))->resolve($request),
        ]);
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        // Clear any memoized guard user so the now-revoked token cannot be
        // resolved again from a cached guard instance within the same lifecycle.
        auth()->forgetGuards();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    /**
     * Return the authenticated user with cabinet, roles and permissions.
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('cabinet.license'));
    }
}
