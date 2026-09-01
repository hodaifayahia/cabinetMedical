<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\LoginRequest;
use App\Http\Requests\Api\Mobile\RegisterRequest;
use App\Http\Resources\Mobile\MobileProfileResource;
use App\Http\Resources\UserResource;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Public mobile authentication. Registration only ever creates PATIENT
 * accounts (cabinet_id = null, zero staff permissions); login serves
 * patients and cabinet staff alike, applying the same cabinet eligibility
 * gate as the desktop API for the latter.
 */
class AuthController extends Controller
{
    private const TOKEN_ABILITIES = ['mobile'];

    private const TOKEN_LIFETIME_DAYS = 90;

    public function __construct(
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * Create a patient account with its demographic profile and sign it in.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'approved_at' => now(),
            ]);

            $user->assignRole(RoleName::PATIENT->value);

            PatientProfile::query()->create([
                'user_id' => $user->getKey(),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'],
                'date_of_birth' => $data['date_of_birth'],
                'wilaya_code' => $data['wilaya_code'],
                'baladiya_id' => $data['baladiya_id'] ?? null,
            ]);

            return $user;
        });

        $token = $user->createToken(
            $data['device_name'] ?? 'mobile',
            self::TOKEN_ABILITIES,
            now()->addDays(self::TOKEN_LIFETIME_DAYS),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'role' => 'patient',
            'user' => (new MobileProfileResource($user->load('patientProfile.baladiya')))->resolve($request),
        ], 201);
    }

    /**
     * Issue a 90-day mobile token for a phone- or email-identified account.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $field = filter_var($data['identifier'], FILTER_VALIDATE_EMAIL) !== false
            ? 'email'
            : 'phone';

        /** @var User|null $user */
        $user = User::query()->where($field, $data['identifier'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['Ces identifiants ne correspondent à aucun compte.'],
            ]);
        }

        // Staff keep the exact cabinet eligibility gate of the desktop API;
        // patient accounts have no cabinet and skip it.
        if (! $user->isMobilePatient()) {
            $reason = $this->access->denialReason($user);
            if ($reason !== null) {
                return response()->json([
                    'message' => $this->access->denialMessage($user),
                    'reason' => $reason,
                    'status' => $this->access->denialStatus($user),
                ], 403);
            }
        }

        $token = $user->createToken(
            $data['device_name'] ?? 'mobile',
            self::TOKEN_ABILITIES,
            now()->addDays(self::TOKEN_LIFETIME_DAYS),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'role' => $user->mobileRole(),
            'user' => $user->isMobilePatient()
                ? (new MobileProfileResource($user->load('patientProfile.baladiya')))->resolve($request)
                : (new UserResource($user->load('cabinet.license')))->resolve($request),
        ]);
    }
}
