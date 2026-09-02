<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\LicensePlan;
use App\Models\AuditLog;
use App\Services\CabinetFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform back office: switch a clinic on and off.
 *
 * All the real work — minting or restoring the hosted entitlement, revoking
 * outstanding licence codes, mailing the owner, writing the lifecycle audit
 * entries — belongs to CabinetFulfillmentService and is not reimplemented
 * here. This controller only maps the current status onto the right service
 * call and turns an impossible transition into a 409 the app can act on
 * instead of a 500.
 */
class AdminCabinetLifecycleController extends AdminController
{
    public function __construct(
        private readonly CabinetFulfillmentService $fulfillment,
    ) {}

    /**
     * PENDING -> ACTIVE (first activation) or SUSPENDED -> ACTIVE (restore).
     */
    public function activate(Request $request, int $cabinet): JsonResponse
    {
        $target = $this->findCabinet($cabinet);

        if ($target->isActive()) {
            return $this->conflict('already_active', 'Ce cabinet est déjà actif.');
        }

        $updated = $target->isSuspended()
            ? $this->fulfillment->reactivate($target)
            : $this->fulfillment->activate($target, LicensePlan::LIFETIME);

        AuditLog::record('admin.cabinet_activated', $updated, [
            'previous_status' => $target->status->value,
            'status' => $updated->status->value,
        ], $request->user()?->getKey());

        return $this->cabinetDetail($updated->refresh())->response();
    }

    /**
     * ACTIVE -> SUSPENDED. A pending clinic has nothing to suspend yet.
     */
    public function suspend(Request $request, int $cabinet): JsonResponse
    {
        $target = $this->findCabinet($cabinet);

        if ($target->isSuspended()) {
            return $this->conflict('already_suspended', 'Ce cabinet est déjà suspendu.');
        }

        if (! $target->isActive()) {
            return $this->conflict(
                'cabinet_not_active',
                "Seul un cabinet actif peut être suspendu : celui-ci est encore en attente d'activation.",
            );
        }

        $updated = $this->fulfillment->suspend($target);

        AuditLog::record('admin.cabinet_suspended', $updated, [
            'previous_status' => $target->status->value,
            'status' => $updated->status->value,
        ], $request->user()?->getKey());

        return $this->cabinetDetail($updated->refresh())->response();
    }

    private function conflict(string $reason, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], 409);
    }
}
