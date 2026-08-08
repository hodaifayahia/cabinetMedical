<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetStatusController extends Controller
{
    public function __construct(
        private readonly CabinetAccessService $access,
    ) {}

    /**
     * "Pending activation" screen shown to owners of a cabinet that has not
     * yet been activated (or has been suspended) by platform staff.
     */
    public function pending(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('dashboard');
        }

        $cabinet = $user->cabinet;
        $reason = $this->access->denialReason($user);
        $cabinet?->loadMissing('license');
        $license = $cabinet?->license;
        $isOwner = $cabinet !== null && $cabinet->owner_user_id === $user->getKey();
        $outstandingGrant = $isOwner
            ? $cabinet->hostedLicenseGrants()->outstanding()->latest()->first()
            : null;
        $isActiveTrialUpgrade = $reason === null
            && $outstandingGrant !== null
            && $cabinet?->isActive() === true
            && $license?->plan?->value === 'trial';

        if (($reason === null && ! $isActiveTrialUpgrade)
            || $reason === CabinetAccessService::REASON_AWAITING_APPROVAL) {
            return redirect()->route('dashboard');
        }

        $canRedeemLicense = $isOwner
            && ($cabinet->isPending()
                || ($cabinet->isActive() && $license?->plan?->value === 'trial'));

        return Inertia::render('auth/PendingActivation', [
            'can_redeem_license' => $canRedeemLicense,
            'pending_license_grant' => $outstandingGrant === null ? null : [
                'plan' => $outstandingGrant->licenseType?->slug ?? $outstandingGrant->plan?->value,
                'plan_label' => $outstandingGrant->typeLabel(),
                'issued_at' => $outstandingGrant->created_at?->toIso8601String(),
                'code_suffix' => $outstandingGrant->code_suffix,
            ],
            'cabinet' => $cabinet === null ? null : [
                'name' => $cabinet->name,
                'status' => $cabinet->status->value,
                'access_status' => $isActiveTrialUpgrade
                    ? 'upgrade'
                    : $this->access->denialStatus($user),
                'access_reason' => $reason,
                'message' => $isActiveTrialUpgrade
                    ? 'Votre cabinet reste accessible. Saisissez ce code pour appliquer immédiatement la nouvelle licence.'
                    : $this->access->denialMessage($user),
                'license' => $license === null ? null : [
                    'plan' => $license->plan?->value,
                    'plan_label' => $license->typeLabel(),
                    'status' => $license->effectiveStatus(),
                    'status_label' => $license->effectiveStatusLabel(),
                    'expires_at' => $license->expires_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * "Awaiting approval" screen shown to members who joined an existing
     * cabinet and are waiting for the owner to approve their account.
     */
    public function awaitingApproval(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPendingApproval()) {
            return redirect()->route('dashboard');
        }

        $cabinet = $user->cabinet;

        return Inertia::render('auth/AwaitingApproval', [
            'cabinet' => $cabinet === null ? null : [
                'name' => $cabinet->name,
            ],
        ]);
    }
}
