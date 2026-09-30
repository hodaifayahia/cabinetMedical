<?php

namespace App\Services\Cabinet;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Services\Sync\MobileSyncClient;
use App\Services\Sync\MobileSyncSettings;
use App\Services\Sync\SyncTransportException;
use Illuminate\Support\Str;

/**
 * Keeps a local desktop's seat allowance in step with the one a platform
 * administrator grants on the online service.
 *
 * The online service is the authority: its cabinet row holds the limit the
 * admin panel edits. A local installation keeps a copy on its own cabinet row
 * so the staff screen works offline, and refreshes that copy whenever it can
 * reach the service with the token it already holds for appointment sync.
 * Being offline is normal; the last copy simply stays in force.
 */
final class CabinetSeatService
{
    public function __construct(
        private readonly MobileSyncClient $client,
        private readonly MobileSyncSettings $settings,
    ) {}

    /**
     * Whether this installation is linked to the online service — for
     * $cabinet, when given. The online service itself never is: it holds the
     * allowance rather than copying it.
     */
    public function canCheckOnline(?Cabinet $cabinet = null): bool
    {
        return $this->settings->endpoint() !== null
            && $this->settings->token() !== null
            && ($cabinet === null || $this->settings->servesCabinet($cabinet->getKey()));
    }

    /**
     * Fetch the current allowance and store it on the local cabinet it
     * belongs to. When $expected is given, the answer must be for that
     * cabinet, so one clinic can never receive another's seats.
     *
     * @throws SyncTransportException offline, not linked, or an answer that
     *                                cannot be matched to a local cabinet
     */
    public function refresh(?Cabinet $expected = null): Cabinet
    {
        if (! $this->canCheckOnline()) {
            throw new SyncTransportException('Ce poste n’est pas relié au service en ligne.');
        }

        $remote = $this->client->seatAllowance();
        $limit = $remote['seat_limit'] ?? null;

        if (! is_int($limit) || $limit < 1 || $limit > Cabinet::MAX_GRANTABLE_SEATS) {
            throw new SyncTransportException('Le service en ligne a renvoyé une réponse illisible.');
        }

        $cabinet = $this->localCabinetFor($remote['owner_email'] ?? null);
        $linked = $this->settings->cabinetId();

        if (($expected !== null && ! $cabinet->is($expected))
            || ($linked !== null && (int) $cabinet->getKey() !== $linked)) {
            throw new SyncTransportException(
                'Le compte en ligne de ce poste appartient à un autre cabinet.',
                reason: SyncTransportException::REASON_CABINET_MISMATCH,
            );
        }

        $previous = $cabinet->seatLimit();

        $cabinet->forceFill([
            'seat_limit' => $limit,
            'seat_limit_synced_at' => now(),
        ])->save();

        if ($previous !== $limit) {
            AuditLog::record('cabinet.seats_synced', $cabinet, [
                'previous_seat_limit' => $previous,
                'seat_limit' => $limit,
            ]);
        }

        $expected?->refresh();

        return $cabinet;
    }

    /**
     * What the staff screen shows about seats.
     *
     * @return array{used: int, limit: int, remaining: int, canCheckOnline: bool, syncedAt: string|null}
     */
    public function summary(Cabinet $cabinet): array
    {
        $used = $cabinet->seatsInUse();
        $limit = $cabinet->seatLimit();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'canCheckOnline' => $this->canCheckOnline($cabinet),
            'syncedAt' => $cabinet->seat_limit_synced_at?->toIso8601String(),
        ];
    }

    /**
     * The online account is matched to a local cabinet by its owner's
     * address, and only by it. This holds on a desktop with a single cabinet
     * too: taking that cabinet whatever the answer said would let a doctor
     * link it with another practice's account, receive that practice's seats
     * and sync this agenda into its online cabinet.
     */
    private function localCabinetFor(mixed $ownerEmail): Cabinet
    {
        if (is_string($ownerEmail) && trim($ownerEmail) !== '') {
            $match = Cabinet::query()
                ->whereHas('owner', fn ($query) => $query->whereRaw('LOWER(email) = ?', [Str::lower(trim($ownerEmail))]))
                ->first();

            if ($match instanceof Cabinet) {
                return $match;
            }
        }

        throw new SyncTransportException(
            'Le compte en ligne de ce poste ne correspond à aucun cabinet de ce poste.',
            reason: SyncTransportException::REASON_CABINET_MISMATCH,
        );
    }
}
