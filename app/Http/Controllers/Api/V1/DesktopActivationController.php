<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Licensing\DesktopActivationRefused;
use App\Licensing\DesktopEntitlementGrantor;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/desktop/activate — the one call an installed desktop makes to
 * be activated. Afterwards it runs on the signed entitlement it received and
 * needs no connection for daily use.
 *
 * Two ways in:
 *  - `license_code`: the single-use code an administrator issued. The grant
 *    only exists in this database, which is why a desktop cannot redeem it on
 *    its own.
 *  - `email` + `password`: the owner of a cabinet already active online
 *    brings it to a new poste. With `link`, the answer also carries the token
 *    that links the poste to the online service (mobile appointments, seats,
 *    AI) in the same step.
 */
class DesktopActivationController extends Controller
{
    public function __invoke(Request $request, DesktopEntitlementGrantor $grantor): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'uuid'],
            'license_code' => ['required_without:email', 'nullable', 'string', 'max:80'],
            'owner_email' => [Rule::requiredIf(fn (): bool => filled($request->input('license_code'))), 'nullable', 'email', 'max:190'],
            'email' => ['required_without:license_code', 'nullable', 'email', 'max:190'],
            'password' => ['required_with:email', 'nullable', 'string', 'max:255'],
            'link' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:250'],
        ]);

        try {
            if (filled($data['license_code'] ?? null)) {
                $result = $grantor->activateWithCode(
                    (string) $data['license_code'],
                    (string) $data['installation_id'],
                    (string) $data['owner_email'],
                );

                return response()->json($this->body($result['entitlement'], $result['cabinet'], $result['license']));
            }

            $result = $grantor->activateWithAccount(
                (string) $data['email'],
                (string) $data['password'],
                (string) $data['installation_id'],
            );
        } catch (DesktopActivationRefused $refused) {
            return response()->json([
                'message' => $refused->getMessage(),
                'reason' => $refused->reason,
            ], $refused->status);
        }

        $body = $this->body($result['entitlement'], $result['cabinet'], $result['license'], $result['owner']);

        if ($request->boolean('link')) {
            $deviceName = Str::limit(trim((string) ($data['device_name'] ?? '')) ?: 'Poste Drclick — '.$result['cabinet']->name, 250, '');
            $body['token'] = $result['owner']->createToken($deviceName)->plainTextToken;
            $body['account'] = [
                'email' => $result['owner']->email,
                'cabinet_name' => $result['cabinet']->name,
            ];
        }

        return response()->json($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $entitlement, Cabinet $cabinet, ?License $license, ?User $owner = null): array
    {
        $cabinet->loadMissing(['owner', 'settings']);
        $owner ??= $cabinet->owner;

        return [
            'entitlement' => $entitlement,
            'license' => $license === null ? null : [
                'plan' => $license->plan?->value,
                'plan_label' => $license->typeLabel(),
                'expires_at' => $license->expires_at?->toIso8601String(),
            ],
            // What a fresh desktop needs to recreate the cabinet locally.
            // Nothing clinical: the records themselves stay on each poste.
            'cabinet' => [
                'name' => $cabinet->name,
                'specialization' => $cabinet->specialization,
                'wilaya_code' => $cabinet->wilaya_code,
                'phone' => $cabinet->settings?->phone,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'seat_limit' => $cabinet->seatLimit(),
            ],
        ];
    }
}
