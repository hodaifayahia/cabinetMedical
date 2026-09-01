<?php

namespace App\Services\Hub;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\HubAuthority;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grants a Cabinet Hub clinical write authority over its bound cabinet, and
 * transfers that authority when a Hub is replaced.
 *
 * This is the offline break-glass ADR-003 requires before identity pinning can
 * be introduced. It works on the LAN alone: no control plane is consulted, and
 * nothing here reaches the network. The trade is that adoption is an explicit,
 * audited, operator-confirmed act rather than something a machine decides for
 * itself — because the whole point is to stop a restored clone from quietly
 * becoming a second write authority for the same cabinet.
 */
final class HubAdoptionService
{
    public function __construct(private readonly HubMode $hub) {}

    /**
     * Whether adoption would create the first authority record for the cabinet
     * rather than displace an existing Hub.
     */
    public function wouldProvision(): bool
    {
        return $this->hub->authority() === null;
    }

    /**
     * Adopt this Hub as the cabinet's write authority.
     *
     * A first adoption starts at epoch 1. Taking over from another Hub raises
     * the epoch, which is what a desktop will later refuse to walk backwards:
     * the displaced machine keeps serving its own stale database at the lower
     * epoch, and every paired desktop will reject it.
     */
    public function adopt(?User $actor = null, ?CarbonImmutable $now = null): HubAuthority
    {
        $now ??= CarbonImmutable::now();

        if (! $this->hub->isDeclared()) {
            throw new LogicException('This installation is not a Cabinet Hub.');
        }

        $hubId = $this->hub->hubId();
        $cabinetId = $this->hub->boundCabinetId();

        if ($hubId === null || $cabinetId === null) {
            throw new LogicException('This Hub has no identity or no bound cabinet, so it cannot hold authority.');
        }

        if (! Cabinet::query()->whereKey($cabinetId)->exists()) {
            throw new LogicException('The bound cabinet does not exist in this database.');
        }

        return DB::transaction(function () use ($hubId, $cabinetId, $actor, $now): HubAuthority {
            // Locked so two operators running this at once on two boxes cannot
            // both believe they won.
            $existing = HubAuthority::query()
                ->where('cabinet_id', $cabinetId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isHeldBy($hubId)) {
                throw new LogicException('This Hub already holds authority for its cabinet.');
            }

            $provisioning = $existing === null;
            $authority = $existing ?? new HubAuthority(['cabinet_id' => $cabinetId]);
            $previousHubId = $provisioning ? null : $existing->hub_id;
            $previousEpoch = $provisioning ? 0 : $existing->authority_epoch;

            $authority->forceFill([
                'cabinet_id' => $cabinetId,
                'hub_id' => $hubId,
                'authority_epoch' => $previousEpoch + 1,
                'adopted_at' => $now,
                'adopted_reason' => $provisioning
                    ? HubAuthority::REASON_PROVISIONED
                    : HubAuthority::REASON_REPLACEMENT,
                'adopted_by_user_id' => $actor?->getKey(),
                'previous_hub_id' => $previousHubId,
            ])->save();

            AuditLog::record('hub.authority_adopted', $authority->cabinet, [
                'hub_id' => $hubId,
                'previous_hub_id' => $previousHubId,
                'authority_epoch' => $authority->authority_epoch,
                'reason' => $authority->adopted_reason,
            ], $actor?->getKey());

            return $authority->refresh();
        });
    }
}
