<?php

namespace App\Actions\Cabinet;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a prospective staff member against an existing active cabinet,
 * identified by its owner's e-mail address. The new member is created in the
 * pending-approval state (no role, approved_at null) and reserves a seat.
 *
 * Shared by the web JoinCabinetController and the Sanctum API so the seat-limit
 * and eligibility rules never diverge.
 */
class JoinCabinetAction
{
    /**
     * @param  array{name: string, email: string, password: string, owner_email: string}  $data
     */
    public function execute(array $data): User
    {
        $cabinetId = Cabinet::query()
            ->whereHas('owner', fn ($query) => $query->where('email', $data['owner_email']))
            ->where('status', 'active')
            ->value('id');

        if ($cabinetId === null) {
            throw ValidationException::withMessages([
                'owner_email' => "Aucun cabinet actif n'a été trouvé pour cette adresse e-mail.",
            ]);
        }

        return DB::transaction(function () use ($data, $cabinetId): User {
            $cabinet = Cabinet::query()
                ->whereKey($cabinetId)
                ->lockForUpdate()
                ->first();

            if (
                $cabinet === null
                || ! $cabinet->isActive()
                || ! $cabinet->owner()->where('email', $data['owner_email'])->exists()
            ) {
                throw ValidationException::withMessages([
                    'owner_email' => "Aucun cabinet actif n'a été trouvé pour cette adresse e-mail.",
                ]);
            }

            // Count every occupant while holding the cabinet lock so pending
            // requests reserve seats and concurrent joins cannot over-allocate.
            if (! $cabinet->hasAvailableSeat()) {
                throw ValidationException::withMessages([
                    'owner_email' => 'Ce cabinet a atteint sa limite de '.Cabinet::MAX_SEATS.' utilisateurs.',
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
