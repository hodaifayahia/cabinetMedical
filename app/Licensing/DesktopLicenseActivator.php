<?php

namespace App\Licensing;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Services\CabinetFulfillmentService;
use App\Services\MachineFingerprintService;
use App\Services\Sync\OnlineServiceLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Activates a cabinet held on an installed desktop.
 *
 * The cabinet's records live on this computer and must keep working when the
 * online service is down, so activation happens once and leaves behind a
 * signed entitlement this poste verifies by itself:
 *
 *  - a code issued by Drclick is redeemed on the online service (where the
 *    grant lives) when it is not found locally;
 *  - the owner of a cabinet already active online signs in with that
 *    account, which also links the poste for mobile appointments;
 *  - with no Internet at all, a signed licence file is imported.
 */
final class DesktopLicenseActivator
{
    public function __construct(
        private readonly CabinetFulfillmentService $fulfillment,
        private readonly CloudActivationClient $cloud,
        private readonly CabinetEntitlementVerifier $verifier,
        private readonly MachineFingerprintService $fingerprint,
        private readonly OnlineServiceLink $link,
        private readonly CabinetProvisioningService $provisioning,
    ) {}

    public function canActivateOnline(): bool
    {
        return $this->cloud->isAvailable();
    }

    public function canImportLicenseFile(): bool
    {
        return $this->verifier->isConfigured();
    }

    /**
     * A code is tried against this database first (the online service
     * itself, or a code its own admin issued locally), then on the online
     * service from an installed desktop.
     *
     * @throws ValidationException under `license_code`
     */
    public function redeemCode(Cabinet $cabinet, User $owner, #[SensitiveParameter] string $code): Cabinet
    {
        try {
            return $this->fulfillment->redeemLicenseCode($cabinet, $owner, $code);
        } catch (ValidationException $exception) {
            if (! $this->cloud->isAvailable() || $cabinet->isSuspended()) {
                throw $exception;
            }
        }

        $activation = $this->cloud->activateWithCode($code, (string) $owner->email, 'license_code');

        return $this->apply($cabinet, $activation->entitlement, 'license_code', 'desktop_license_code');
    }

    /**
     * The owner signs in with the cabinet's online account. Besides the
     * licence, the answer carries a token that links the poste to the online
     * service, so mobile appointments start flowing without a second step.
     *
     * @return array{cabinet: Cabinet, linked: bool, link_error: string|null}
     *
     * @throws ValidationException under `online_email`
     */
    public function activateWithOnlineAccount(
        Cabinet $cabinet,
        User $owner,
        string $email,
        #[SensitiveParameter] string $password,
    ): array {
        if (Str::lower(trim($email)) !== Str::lower(trim((string) $owner->email))) {
            throw ValidationException::withMessages([
                'online_email' => 'Utilisez le compte en ligne du titulaire de ce cabinet ('.$owner->email.'). '
                    .'Les deux comptes doivent avoir la même adresse e-mail.',
            ]);
        }

        $linkWanted = ! $this->link->isLinked();
        $activation = $this->cloud->activateWithAccount(
            $email,
            $password,
            $linkWanted,
            'Poste Drclick — '.$cabinet->name,
        );

        $cabinet = $this->apply($cabinet, $activation->entitlement, 'online_email', 'desktop_online_account');
        [$linked, $linkError] = $this->adoptLink($cabinet, $activation);

        return ['cabinet' => $cabinet, 'linked' => $linked, 'link_error' => $linkError];
    }

    /**
     * Fully offline activation from a signed licence file.
     *
     * @throws ValidationException under `entitlement`
     */
    public function applyLicenseFile(Cabinet $cabinet, #[SensitiveParameter] string $envelope): Cabinet
    {
        if (! $this->verifier->isConfigured()) {
            throw ValidationException::withMessages([
                'entitlement' => 'Ce poste ne peut pas vérifier les licences Drclick : la clé de vérification est absente de cette installation.',
            ]);
        }

        try {
            $entitlement = $this->verifier->verify(trim($envelope));
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'entitlement' => 'Ce fichier de licence est invalide ou a été modifié : sa signature est refusée.',
            ]);
        }

        return $this->apply($cabinet, $entitlement, 'entitlement', 'desktop_license_file');
    }

    /**
     * First half of moving an online cabinet's records to this empty PC:
     * the online service confirms the owner and returns the licence and
     * the token the transfer then uses. Nothing is written locally yet.
     *
     * @throws ValidationException under `email`
     */
    public function activateForTransfer(string $email, #[SensitiveParameter] string $password): CloudActivation
    {
        $activation = $this->cloud->activateWithAccount($email, $password, true, 'Poste Drclick', 'email');

        if ($activation->token === null) {
            throw ValidationException::withMessages([
                'email' => 'Le service en ligne n’a pas autorisé le transfert des dossiers. Réessayez plus tard.',
            ]);
        }

        return $activation;
    }

    /**
     * Second half: once the cabinet's rows are on this PC, activate that
     * cabinet with the licence received and link it for the mobile app.
     */
    public function adoptTransferredCabinet(Cabinet $cabinet, CloudActivation $activation): Cabinet
    {
        $cabinet = $this->apply($cabinet, $activation->entitlement, 'email', 'desktop_online_transfer');
        $seatLimit = $activation->cabinet['seat_limit'] ?? null;

        if (is_int($seatLimit) && $seatLimit >= 1 && $seatLimit <= Cabinet::MAX_GRANTABLE_SEATS) {
            $cabinet->forceFill(['seat_limit' => $seatLimit, 'seat_limit_synced_at' => now()])->save();
        }

        $this->adoptLink($cabinet, $activation);

        return $cabinet->refresh();
    }

    /**
     * "Cabinet existant" on a fresh desktop: no local account matches, so the
     * owner's online account is used to recreate the cabinet here — identity
     * only, the records never leave the other postes — activate it and link
     * it to the online service.
     *
     * @throws ValidationException under `email`
     */
    public function importOnlineCabinet(string $email, #[SensitiveParameter] string $password): User
    {
        $activation = $this->cloud->activateWithAccount(
            $email,
            $password,
            true,
            'Poste Drclick',
            'email',
        );

        $remote = $activation->cabinet;
        $ownerEmail = $activation->entitlement->ownerEmail;

        if (User::query()->whereRaw('LOWER(email) = ?', [$ownerEmail])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Un compte local utilise déjà cette adresse sur ce poste. Connectez-vous avec son mot de passe local.',
            ]);
        }

        $owner = DB::transaction(function () use ($remote, $ownerEmail, $password, $activation): User {
            $owner = $this->provisioning->provision([
                'name' => $this->text($remote['owner_name'] ?? null) ?? Str::before($ownerEmail, '@'),
                'email' => $ownerEmail,
                'password' => $password,
                'phone' => $this->text($remote['phone'] ?? null) ?? '',
                'cabinet_name' => $this->text($remote['name'] ?? null) ?? 'Mon cabinet',
                'specialization' => $this->text($remote['specialization'] ?? null) ?? 'Médecine générale',
                'wilaya' => is_numeric($remote['wilaya_code'] ?? null) ? (int) $remote['wilaya_code'] : 16,
            ]);

            $this->apply($owner->cabinet, $activation->entitlement, 'email', 'desktop_online_import');

            return $owner;
        });

        $cabinet = $owner->cabinet()->firstOrFail();
        $seatLimit = $remote['seat_limit'] ?? null;

        if (is_int($seatLimit) && $seatLimit >= 1 && $seatLimit <= Cabinet::MAX_GRANTABLE_SEATS) {
            $cabinet->forceFill(['seat_limit' => $seatLimit, 'seat_limit_synced_at' => now()])->save();
        }

        $this->adoptLink($cabinet, $activation);

        return $owner->refresh();
    }

    /**
     * @throws ValidationException
     */
    private function apply(Cabinet $cabinet, CabinetEntitlement $entitlement, string $errorKey, string $method): Cabinet
    {
        try {
            $activated = $this->fulfillment->activateFromOfflineEntitlement(
                $entitlement,
                $this->fingerprint->installationId(),
                expected: $cabinet,
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                $errorKey => str_replace('un autre Hub', 'un autre poste', $exception->getMessage()),
            ]);
        }

        AuditLog::record('cabinet.desktop_activated', $activated, [
            'activation_method' => $method,
            ...$entitlement->auditContext(),
        ], $activated->owner_user_id);

        return $activated;
    }

    /**
     * Linking is a bonus of activating with the online account: a failure
     * here never undoes the activation; the doctor can link later from
     * Configuration › Service en ligne.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function adoptLink(Cabinet $cabinet, CloudActivation $activation): array
    {
        if ($activation->token === null || $this->link->isLinked()) {
            return [false, null];
        }

        try {
            $this->link->adopt(
                $cabinet,
                $activation->endpoint,
                $activation->token,
                $activation->accountEmail ?? $activation->entitlement->ownerEmail,
                $activation->accountCabinetName,
            );

            return [true, null];
        } catch (ValidationException $exception) {
            return [false, collect($exception->errors())->flatten()->first()];
        } catch (Throwable $exception) {
            Log::warning('The desktop was activated but could not be linked to the online service.', [
                'cabinet_id' => $cabinet->getKey(),
                'error_type' => $exception::class,
            ]);

            return [false, null];
        }
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
