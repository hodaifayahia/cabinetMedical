<?php

namespace App\Services;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Mail\CabinetActivatedMail;
use App\Mail\CabinetLicenseCodeIssuedMail;
use App\Mail\CabinetLicenseUpdatedMail;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\HostedLicenseGrant;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\User;
use App\Support\IssuedHostedLicenseCode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Server-side cabinet lifecycle operations used by the Filament back office
 * (and reusable from a future API). One-time activation mints one hosted
 * entitlement and links it to the cabinet. Later trial renewals update that
 * same row; they never invoke or replace the signed desktop licence service.
 */
class CabinetFulfillmentService
{
    private const LICENSE_CODE_PREFIX = 'DRDZ';

    /**
     * Activate a pending cabinet with the selected hosted plan, link its one
     * licence, flip the status to active and notify the owner. Idempotent for
     * already-active cabinets (returns without re-issuing or changing plan).
     */
    public function activate(Cabinet $cabinet, LicensePlan $plan = LicensePlan::LIFETIME): Cabinet
    {
        [$cabinet, $wasActivated] = DB::transaction(function () use ($cabinet, $plan): array {
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());

            if ($lockedCabinet->isActive()) {
                return [$lockedCabinet, false];
            }

            if (! $lockedCabinet->isPending()) {
                throw new LogicException('Only a pending cabinet may be activated.');
            }

            $this->revokeOutstandingLicenseCodes($lockedCabinet, $this->authenticatedActorId());
            $license = $this->issueLicense($lockedCabinet, $plan);

            $lockedCabinet->forceFill([
                'status' => CabinetStatus::ACTIVE,
                'activated_at' => now(),
                'license_id' => $license->getKey(),
            ])->save();

            AuditLog::record('cabinet.activated', $lockedCabinet, [
                'license_id' => $license->license_id,
                'license_plan' => $plan->value,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'owner_user_id' => $lockedCabinet->owner_user_id,
            ], $this->authenticatedActorId());

            return [$lockedCabinet, true];
        });

        if ($wasActivated) {
            $this->notifyOwner($cabinet);
        }

        return $cabinet;
    }

    /**
     * Prepare a cabinet-bound, single-use code without activating anything.
     * Regeneration serializes on the cabinet row and revokes every earlier
     * outstanding grant, so at most one code can be accepted for a cabinet.
     */
    public function issueLicenseCode(Cabinet $cabinet, LicensePlan|LicenseType $plan): IssuedHostedLicenseCode
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->is_platform_admin) {
            throw new AuthorizationException('Only a platform administrator may issue hosted licence codes.');
        }

        [$issued, $lockedCabinet] = DB::transaction(function () use ($cabinet, $plan, $actor): array {
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());

            $this->assertCodeMayBeIssued($lockedCabinet);

            $revokedCount = HostedLicenseGrant::withoutCabinetScope()
                ->where('cabinet_id', $lockedCabinet->getKey())
                ->outstanding()
                ->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $plainCode = $this->generateLicenseCode();
            $normalizedCode = $this->normalizeLicenseCode($plainCode);

            $grant = HostedLicenseGrant::withoutCabinetScope()->create([
                'cabinet_id' => $lockedCabinet->getKey(),
                'issued_by_user_id' => $actor->getKey(),
                'plan' => $this->planForType($plan)?->value,
                'license_type_id' => $plan instanceof LicenseType ? $plan->getKey() : $this->typeForPlan($plan)?->getKey(),
                // Keep the configured duration exact. Calculating this with
                // diffInDays() can produce 6.999... for a seven-day plan and
                // truncating that float would incorrectly issue six days.
                'duration_days' => $plan instanceof LicenseType
                    ? $plan->duration_days
                    : ($plan === LicensePlan::TRIAL ? LicensePlan::TRIAL_DAYS : null),
                'type_name' => $plan instanceof LicenseType ? $plan->name : $plan->label(),
                'code_hash' => $this->hashLicenseCode($normalizedCode),
                'code_suffix' => Str::substr($normalizedCode, -4),
            ]);

            AuditLog::record('cabinet.license_code_issued', $lockedCabinet, [
                'grant_id' => $grant->getKey(),
                'license_plan' => $plan instanceof LicensePlan ? $plan->value : $plan->slug,
                'grant_suffix' => $grant->code_suffix,
                'revoked_pending_grants_count' => $revokedCount,
                'owner_user_id' => $lockedCabinet->owner_user_id,
            ], $actor->getKey());

            return [new IssuedHostedLicenseCode($grant, $plainCode), $lockedCabinet];
        });

        $this->notifyOwnerOfLicenseCode($lockedCabinet, $issued);

        return $issued;
    }

    /**
     * Redeem a hosted code atomically. A pending cabinet receives its sole
     * entitlement here; an existing trial is renewed or upgraded in place.
     * The trial clock starts at this successful redemption instant.
     */
    public function redeemLicenseCode(Cabinet $cabinet, User $owner, string $plainCode): Cabinet
    {
        if ($cabinet->owner_user_id !== $owner->getKey() || $owner->cabinet_id !== $cabinet->getKey()) {
            throw new AuthorizationException('Only the cabinet owner may redeem its licence code.');
        }

        $codeHash = $this->hashLicenseCode($this->normalizeLicenseCode($plainCode));

        [$redeemedCabinet, $license, $initialActivation] = DB::transaction(function () use ($cabinet, $owner, $codeHash): array {
            // Lock the cabinet before the grant in both issue and redeem paths,
            // preventing regeneration and double redemption from interleaving.
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());

            if ($lockedCabinet->owner_user_id !== $owner->getKey()) {
                throw new AuthorizationException('Only the cabinet owner may redeem its licence code.');
            }

            $grant = HostedLicenseGrant::withoutCabinetScope()
                ->where('cabinet_id', $lockedCabinet->getKey())
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($grant === null || ! $grant->isOutstanding()) {
                $this->throwInvalidLicenseCode();
            }

            $redeemedAt = CarbonImmutable::now();
            $initialActivation = false;
            $previousPlan = null;
            $previousExpiry = null;

            if ($lockedCabinet->isPending()) {
                if ($lockedCabinet->license_id !== null) {
                    $this->throwInvalidLicenseCode();
                }

                $license = $this->issueLicense(
                    $lockedCabinet,
                    $grant->licenseType ?? $grant->plan,
                    $redeemedAt,
                    [
                        'source' => 'hosted_license_code',
                        'grant_id' => $grant->getKey(),
                        'redeemed_by_user_id' => $owner->getKey(),
                    ],
                );

                $lockedCabinet->forceFill([
                    'status' => CabinetStatus::ACTIVE,
                    'activated_at' => $redeemedAt,
                    'license_id' => $license->getKey(),
                ])->save();
                $initialActivation = true;

                AuditLog::record('cabinet.activated', $lockedCabinet, [
                    'license_id' => $license->license_id,
                    'license_plan' => $grant->typeLabel(),
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'grant_id' => $grant->getKey(),
                    'activation_method' => 'license_code',
                    'owner_user_id' => $owner->getKey(),
                ], $owner->getKey());
            } elseif ($lockedCabinet->isActive()) {
                $license = $this->hostedLicenseForUpdate($lockedCabinet);

                if ($license === null || $license->plan !== LicensePlan::TRIAL || $license->status === 'revoked') {
                    $this->throwInvalidLicenseCode();
                }

                $previousPlan = $license->plan;
                $previousExpiry = $license->expires_at;
                $license->forceFill([
                    'plan' => $grant->plan,
                    'license_type_id' => $grant->license_type_id,
                    'status' => 'active',
                    'expires_at' => $grant->expiresAt($redeemedAt),
                    'offline_grace_until' => null,
                    'last_verified_at' => $redeemedAt,
                    'last_server_response' => array_merge($license->last_server_response ?? [], [
                        'source' => 'hosted_license_code',
                        'cabinet_id' => $lockedCabinet->getKey(),
                        'one_time_activation' => true,
                        'plan' => $grant->typeLabel(),
                        'grant_id' => $grant->getKey(),
                        'redeemed_at' => $redeemedAt->toIso8601String(),
                    ]),
                ])->save();

                AuditLog::record('cabinet.license_renewed', $lockedCabinet, [
                    'previous_plan' => $previousPlan->value,
                    'new_plan' => $grant->typeLabel(),
                    'previous_expires_at' => $previousExpiry?->toIso8601String(),
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'grant_id' => $grant->getKey(),
                    'activation_method' => 'license_code',
                    'owner_user_id' => $owner->getKey(),
                ], $owner->getKey());
            } else {
                throw ValidationException::withMessages([
                    'license_code' => 'Ce cabinet est suspendu. Contactez l’administration Drclick.',
                ]);
            }

            $grant->forceFill([
                'redeemed_by_user_id' => $owner->getKey(),
                'redeemed_at' => $redeemedAt,
            ])->save();

            AuditLog::record('cabinet.license_code_redeemed', $lockedCabinet, [
                'grant_id' => $grant->getKey(),
                'license_id' => $license->license_id,
                'license_plan' => $grant->typeLabel(),
                'initial_activation' => $initialActivation,
                'grant_suffix' => $grant->code_suffix,
            ], $owner->getKey());

            return [$lockedCabinet->load(['owner', 'license']), $license->refresh(), $initialActivation];
        });

        if ($initialActivation) {
            $this->notifyOwner($redeemedCabinet);
        } else {
            $this->notifyOwnerOfLicenseUpdate($redeemedCabinet, $license);
        }

        return $redeemedCabinet;
    }

    public function suspend(Cabinet $cabinet): Cabinet
    {
        return DB::transaction(function () use ($cabinet): Cabinet {
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());

            if ($lockedCabinet->isSuspended()) {
                return $lockedCabinet;
            }

            if (! $lockedCabinet->isActive()) {
                throw new LogicException('Only an active cabinet may be suspended.');
            }

            $this->revokeOutstandingLicenseCodes($lockedCabinet, $this->authenticatedActorId());
            $lockedCabinet->forceFill(['status' => CabinetStatus::SUSPENDED])->save();

            $license = $this->hostedLicenseForUpdate($lockedCabinet);
            if ($license !== null && in_array($license->status, ['active', 'expired', 'suspended'], true)) {
                $license->forceFill(['status' => 'suspended'])->save();
            }

            AuditLog::record('cabinet.suspended', $lockedCabinet, [
                'owner_user_id' => $lockedCabinet->owner_user_id,
                'license_plan' => $license?->plan?->value,
            ], $this->authenticatedActorId());

            return $lockedCabinet->load('license');
        });
    }

    public function reactivate(Cabinet $cabinet): Cabinet
    {
        return DB::transaction(function () use ($cabinet): Cabinet {
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());

            if ($lockedCabinet->isActive()) {
                return $lockedCabinet;
            }

            if (! $lockedCabinet->isSuspended()) {
                throw new LogicException('Only a suspended cabinet may be reactivated.');
            }

            $lockedCabinet->forceFill([
                'status' => CabinetStatus::ACTIVE,
                'activated_at' => $lockedCabinet->activated_at ?? now(),
            ])->save();

            $license = $this->hostedLicenseForUpdate($lockedCabinet);
            if ($license !== null && in_array($license->status, ['active', 'expired', 'suspended'], true)) {
                $license->forceFill([
                    'status' => $license->isExpired() ? 'expired' : 'active',
                ])->save();
            }

            AuditLog::record('cabinet.reactivated', $lockedCabinet, [
                'owner_user_id' => $lockedCabinet->owner_user_id,
                'license_status' => $license?->effectiveStatus(),
            ], $this->authenticatedActorId());

            return $lockedCabinet->load('license');
        });
    }

    /**
     * Renew an existing trial for seven days or upgrade it permanently. The
     * original licence row and cabinet activation timestamp are retained, so
     * fulfillment remains a one-time operation. An active expired cabinet is
     * restored immediately; a suspended cabinet remains suspended.
     */
    public function renewTrial(Cabinet $cabinet, LicensePlan $plan): Cabinet
    {
        [$cabinet, $license] = DB::transaction(function () use ($cabinet, $plan): array {
            $lockedCabinet = Cabinet::query()
                ->lockForUpdate()
                ->findOrFail((int) $cabinet->getKey());
            $license = $this->hostedLicenseForUpdate($lockedCabinet);

            if ($license === null || $license->plan !== LicensePlan::TRIAL) {
                throw new LogicException('Only an existing trial licence may be renewed or upgraded.');
            }

            if ($license->status === 'revoked') {
                throw new LogicException('A revoked licence cannot be renewed.');
            }

            $this->revokeOutstandingLicenseCodes($lockedCabinet, $this->authenticatedActorId());
            $startsAt = CarbonImmutable::now();
            $previousExpiry = $license->expires_at;
            $license->forceFill([
                'plan' => $plan,
                'status' => $lockedCabinet->isSuspended() ? 'suspended' : 'active',
                'expires_at' => $plan->expiresAt($startsAt),
                'offline_grace_until' => null,
                'last_verified_at' => $startsAt,
                'last_server_response' => array_merge($license->last_server_response ?? [], [
                    'source' => 'central_fulfillment',
                    'cabinet_id' => $lockedCabinet->getKey(),
                    'one_time_activation' => true,
                    'plan' => $plan->value,
                    'renewed_at' => $startsAt->toIso8601String(),
                ]),
            ])->save();

            AuditLog::record('cabinet.license_renewed', $lockedCabinet, [
                'previous_plan' => LicensePlan::TRIAL->value,
                'new_plan' => $plan->value,
                'previous_expires_at' => $previousExpiry?->toIso8601String(),
                'expires_at' => $license->expires_at?->toIso8601String(),
                'owner_user_id' => $lockedCabinet->owner_user_id,
            ], $this->authenticatedActorId());

            return [$lockedCabinet->load('license'), $license->refresh()];
        });

        $this->notifyOwnerOfLicenseUpdate($cabinet, $license);

        return $cabinet;
    }

    /**
     * Mint a server-issued hosted licence record for the cabinet. The
     * signed_certificate stays empty (populated to '' by the License model);
     * the central server treats the DB row itself as the authority, unlike the
     * desktop client which verifies a signed certificate.
     */
    /** @param array<string, mixed> $responseContext */
    private function issueLicense(
        Cabinet $cabinet,
        LicensePlan|LicenseType $plan,
        ?CarbonImmutable $issuedAt = null,
        array $responseContext = [],
    ): License {
        $issuedAt ??= CarbonImmutable::now();

        return License::query()->create([
            'license_id' => 'CAB-'.$cabinet->getKey().'-'.Str::upper(Str::random(10)),
            'product' => (string) config('medismart.licensing.product', config('app.name', 'ClickDZ')),
            'edition' => 'hosted',
            'plan' => $this->planForType($plan),
            'license_type_id' => $plan instanceof LicenseType ? $plan->getKey() : $this->typeForPlan($plan)?->getKey(),
            'customer_id' => (string) $cabinet->getKey(),
            'status' => 'active',
            'issued_at' => $issuedAt,
            'expires_at' => $plan instanceof LicenseType ? $plan->expiresAt($issuedAt) : $plan->expiresAt($issuedAt),
            'offline_grace_until' => null,
            'last_verified_at' => $issuedAt,
            'last_server_response' => array_merge([
                'source' => 'central_fulfillment',
                'cabinet_id' => $cabinet->getKey(),
                'one_time_activation' => true,
                'plan' => $plan instanceof LicensePlan ? $plan->value : $plan->slug,
            ], $responseContext),
        ]);
    }

    private function planForType(LicensePlan|LicenseType|null $type): ?LicensePlan
    {
        if ($type instanceof LicensePlan) {
            return $type;
        }

        return match ($type?->slug) {
            'trial', 'trial-7-days' => LicensePlan::TRIAL,
            'lifetime' => LicensePlan::LIFETIME,
            default => null,
        };
    }

    private function typeForPlan(LicensePlan $plan): ?LicenseType
    {
        return LicenseType::query()->where('slug', $plan === LicensePlan::TRIAL ? 'trial-7-days' : 'lifetime')->first();
    }

    private function assertCodeMayBeIssued(Cabinet $cabinet): void
    {
        if ($cabinet->isPending()) {
            if ($cabinet->license_id !== null) {
                throw new LogicException('A pending cabinet with an existing licence cannot receive a code.');
            }

            return;
        }

        if (! $cabinet->isActive()) {
            throw new LogicException('Suspended cabinets cannot receive licence codes.');
        }

        $license = $this->hostedLicenseForUpdate($cabinet);

        if ($license === null || $license->plan !== LicensePlan::TRIAL || $license->status === 'revoked') {
            throw new LogicException('Only a pending cabinet or an existing trial may receive a licence code.');
        }
    }

    private function generateLicenseCode(): string
    {
        $payload = Str::upper(bin2hex(random_bytes(16)));

        return self::LICENSE_CODE_PREFIX.'-'.implode('-', str_split($payload, 4));
    }

    private function normalizeLicenseCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(trim($code)));
    }

    private function hashLicenseCode(string $normalizedCode): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new LogicException('APP_KEY is required to issue or redeem hosted licence codes.');
        }

        return hash_hmac('sha256', $normalizedCode, $key);
    }

    private function throwInvalidLicenseCode(): never
    {
        throw ValidationException::withMessages([
            'license_code' => 'Ce code de licence est invalide ou n’est plus disponible.',
        ]);
    }

    private function revokeOutstandingLicenseCodes(Cabinet $cabinet, ?int $actorId): int
    {
        return HostedLicenseGrant::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->outstanding()
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actorId,
                'updated_at' => now(),
            ]);
    }

    private function authenticatedActorId(): ?int
    {
        $actorId = auth()->id();

        return is_numeric($actorId) ? (int) $actorId : null;
    }

    private function hostedLicenseForUpdate(Cabinet $cabinet): ?License
    {
        if ($cabinet->license_id === null) {
            return null;
        }

        $license = License::query()
            ->lockForUpdate()
            ->find($cabinet->license_id);

        return $license?->isHostedEntitlement() === true ? $license : null;
    }

    private function notifyOwner(Cabinet $cabinet): void
    {
        $cabinet->loadMissing(['owner', 'license']);
        $owner = $cabinet->owner;

        if ($owner === null || blank($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new CabinetActivatedMail($cabinet, $owner->name));
        } catch (Throwable $exception) {
            // Never fail activation because mail is misconfigured.
            Log::warning('Cabinet activation e-mail could not be sent.', [
                'cabinet_id' => $cabinet->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyOwnerOfLicenseCode(
        Cabinet $cabinet,
        IssuedHostedLicenseCode $issued,
    ): void {
        $cabinet->loadMissing('owner');
        $owner = $cabinet->owner;

        if ($owner === null || blank($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new CabinetLicenseCodeIssuedMail(
                $cabinet,
                $issued->grant,
                $owner->name,
                $issued->code,
            ));
        } catch (Throwable $exception) {
            Log::warning('Cabinet licence-code e-mail could not be sent.', [
                'cabinet_id' => $cabinet->getKey(),
                'grant_id' => $issued->grant->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyOwnerOfLicenseUpdate(Cabinet $cabinet, License $license): void
    {
        $owner = $cabinet->owner;

        if ($owner === null || blank($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new CabinetLicenseUpdatedMail($cabinet, $license, $owner->name));
        } catch (Throwable $exception) {
            Log::warning('Cabinet licence update e-mail could not be sent.', [
                'cabinet_id' => $cabinet->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
