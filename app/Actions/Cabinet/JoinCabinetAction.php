<?php

namespace App\Actions\Cabinet;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registers a prospective staff member against an existing cabinet, identified
 * by its owner's e-mail address. The new member is created in the
 * pending-approval state (no role, approved_at null) and reserves a seat.
 *
 * A cabinet that is still awaiting its activation licence accepts members too.
 * Its owner registers, waits for the licence, and needs the reception desk on a
 * second machine ready by the time the cabinet goes live; EnsureCabinetIsActive
 * still holds every member out of the application until then, so joining early
 * grants no access. Only a suspended cabinet refuses new members outright.
 *
 * Shared by the web JoinCabinetController and the Sanctum API so the seat-limit
 * and eligibility rules never diverge.
 */
class JoinCabinetAction
{
    /** @var list<string> */
    private const JOINABLE_STATUSES = ['active', 'pending'];

    private const NO_CABINET_MESSAGE = "Aucun cabinet n'a été trouvé pour cette adresse e-mail.";

    /**
     * @param  array{name: string, email: string, password: string, owner_email: string}  $data
     */
    public function execute(array $data): User
    {
        $ownerEmail = Str::lower(trim($data['owner_email']));

        $cabinetId = Cabinet::query()
            ->whereHas('owner', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$ownerEmail]))
            ->whereIn('status', self::JOINABLE_STATUSES)
            ->value('id');

        if ($cabinetId === null) {
            throw ValidationException::withMessages([
                'owner_email' => self::NO_CABINET_MESSAGE,
            ]);
        }

        return DB::transaction(function () use ($data, $cabinetId, $ownerEmail): User {
            $cabinet = Cabinet::query()
                ->whereKey($cabinetId)
                ->lockForUpdate()
                ->first();

            if (
                $cabinet === null
                || ! in_array($cabinet->status->value, self::JOINABLE_STATUSES, true)
                || ! $cabinet->owner()->whereRaw('LOWER(email) = ?', [$ownerEmail])->exists()
            ) {
                throw ValidationException::withMessages([
                    'owner_email' => self::NO_CABINET_MESSAGE,
                ]);
            }

            // Count every occupant while holding the cabinet lock so pending
            // requests reserve seats and concurrent joins cannot over-allocate.
            if (! $cabinet->hasAvailableSeat()) {
                throw ValidationException::withMessages([
                    'owner_email' => 'Ce cabinet a atteint sa limite de '.$cabinet->seatLimit().' utilisateurs.',
                ]);
            }

            $member = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'cabinet_id' => $cabinet->getKey(),
            ]);
            $member->forceFill([
                'email_verified_at' => now(),
                'approved_at' => null,
            ])->save();

            AuditLog::record('cabinet.join_requested', $member, [
                'cabinet_id' => $cabinet->getKey(),
            ], $member->getKey());

            return $member;
        });
    }
}
