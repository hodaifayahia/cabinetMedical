<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\RoleName;
use App\Http\Requests\Api\Mobile\Admin\StoreAdminStaffRequest;
use App\Http\Resources\Mobile\Admin\AdminUserResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Platform back office: add a reception account to an existing clinic.
 *
 * The role is fixed to Assistant — the platform never mints a second owner or
 * another superadmin through HTTP — and the account is approved on creation,
 * matching what the clinic owner gets from the web staff screen. Seats are
 * allocated under a row lock on the cabinet so two admins adding a receptionist
 * at the same moment cannot together exceed Cabinet::MAX_SEATS.
 */
class AdminCabinetStaffController extends AdminController
{
    public function store(StoreAdminStaffRequest $request, int $cabinet): JsonResponse
    {
        $target = $this->findCabinet($cabinet);
        $data = $request->validated();
        $actor = $request->user();
        [$password, $temporaryPassword] = $this->resolveInitialPassword($data['password'] ?? null);

        $member = DB::transaction(function () use ($target, $data, $password, $actor): ?User {
            /** @var Cabinet $locked */
            $locked = Cabinet::query()
                ->withoutGlobalScopes()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Pending and approved members alike occupy a seat.
            if (User::query()->where('cabinet_id', $locked->getKey())->count() >= Cabinet::MAX_SEATS) {
                return null;
            }

            $settings = CabinetSetting::current($locked);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'cabinet_id' => $locked->getKey(),
                'cabinet_setting_id' => $settings->getKey(),
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'approved_at' => now(),
            ])->save();
            $user->syncRoles([RoleName::ASSISTANT->value]);

            AuditLog::record('admin.staff_provisioned', $user, [
                'cabinet_id' => $locked->getKey(),
                'role' => RoleName::ASSISTANT->value,
                // See AdminCabinetController: a key containing "password" is
                // redacted wholesale by AuditLog, so the source of the initial
                // credential is recorded under a name that survives.
                'credential_source' => filled($data['password'] ?? null) ? 'supplied' : 'generated',
            ], $actor?->getKey());

            return $user;
        });

        if ($member === null) {
            return response()->json([
                'message' => 'Ce cabinet a atteint sa limite de '.Cabinet::MAX_SEATS.' utilisateurs.',
                'reason' => 'seat_limit_reached',
            ], 409);
        }

        return (new AdminUserResource($member))
            ->additional(['temporary_password' => $temporaryPassword])
            ->response()
            ->setStatusCode(201);
    }
}
