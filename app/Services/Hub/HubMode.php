<?php

namespace App\Services\Hub;

use App\Models\Cabinet;
use App\Models\User;

/**
 * Describes whether this installation is a Cabinet Hub and, if so, which single
 * cabinet it is the clinical write authority for.
 *
 * ADR-002 invariant 1 allows exactly one write authority per cabinet, and
 * invariant 4 binds a Hub to exactly one cabinet. Both are enforced from here
 * so the middleware, the health endpoint, the registration guard and the
 * console command can never disagree about what this machine is.
 */
final class HubMode
{
    public const MISCONFIGURED_REASON_NO_ID = 'hub_id_missing';

    public const MISCONFIGURED_REASON_NO_CABINET = 'hub_cabinet_missing';

    public const MISCONFIGURED_REASON_CABINET_UNKNOWN = 'hub_cabinet_not_in_database';

    /**
     * Whether the operator has declared this machine a Hub, regardless of
     * whether that declaration is complete enough to act on.
     */
    public function isDeclared(): bool
    {
        return (bool) config('hub.enabled', false);
    }

    /**
     * Whether this machine is a Hub that knows which cabinet it serves. A Hub
     * that cannot answer that question is not usable as one.
     */
    public function isEnabled(): bool
    {
        return $this->isDeclared()
            && $this->hubId() !== null
            && $this->boundCabinetId() !== null;
    }

    public function hubId(): ?string
    {
        return $this->stringOrNull(config('hub.id'));
    }

    public function hostname(): ?string
    {
        return $this->stringOrNull(config('hub.hostname'));
    }

    public function tlsSpkiSha256(): ?string
    {
        return $this->stringOrNull(config('hub.tls_spki_sha256'));
    }

    public function protocolVersion(): int
    {
        return (int) config('hub.protocol_version', 1);
    }

    public function boundCabinetId(): ?int
    {
        $value = config('hub.cabinet_id');

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit(trim($value)) && (int) trim($value) > 0) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * The bound cabinet as stored, or null when the binding names a cabinet
     * this database does not contain — a restored or mismatched Hub.
     */
    public function boundCabinet(): ?Cabinet
    {
        $cabinetId = $this->boundCabinetId();

        if ($cabinetId === null) {
            return null;
        }

        return Cabinet::query()->find($cabinetId);
    }

    /**
     * Whether a user may be served by this machine.
     *
     * Off a Hub every user is served as before. On a Hub only members of the
     * bound cabinet are, including platform administrators: a Hub is a
     * cabinet's data plane, never a control plane, so an account with no
     * cabinet of its own has no business on it either.
     */
    public function serves(User $user): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return $user->cabinet_id !== null
            && (int) $user->cabinet_id === $this->boundCabinetId();
    }

    /**
     * Why a declared Hub is not usable, or null when it is fine.
     */
    public function misconfigurationReason(): ?string
    {
        if (! $this->isDeclared()) {
            return null;
        }

        if ($this->hubId() === null) {
            return self::MISCONFIGURED_REASON_NO_ID;
        }

        if ($this->boundCabinetId() === null) {
            return self::MISCONFIGURED_REASON_NO_CABINET;
        }

        if ($this->boundCabinet() === null) {
            return self::MISCONFIGURED_REASON_CABINET_UNKNOWN;
        }

        return null;
    }

    /**
     * The identity a desktop reads from /health to confirm it reached the Hub
     * it paired with. It carries no medical data and no secret: the SPKI
     * fingerprint is a public certificate property, and a client that cannot
     * already reach the Hub learns nothing useful from it.
     *
     * @return array<string, mixed>|null
     */
    public function advertisement(): ?array
    {
        if (! $this->isDeclared()) {
            return null;
        }

        return [
            'mode' => 'hub',
            'protocol_version' => $this->protocolVersion(),
            'hub_id' => $this->hubId(),
            'cabinet_id' => $this->boundCabinetId(),
            'hostname' => $this->hostname(),
            'tls_spki_sha256' => $this->tlsSpkiSha256(),
            'ready' => $this->misconfigurationReason() === null,
            'reason' => $this->misconfigurationReason(),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
