<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\Admin\AdminCabinetResource;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use App\Models\Wilaya;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the platform back office.
 *
 * A platform admin has `cabinet_id = null`, which makes the BelongsToCabinet
 * global scope a no-op for every query they run: it narrows nothing, so an
 * "implicitly scoped" read would silently return the whole platform. Nothing
 * here therefore leans on that scope. Cross-tenant reads say
 * `withoutCabinetScope()` out loud and constrain `cabinet_id` themselves, and
 * the cabinet lookup runs without global scopes on purpose.
 */
abstract class AdminController extends Controller
{
    /**
     * Length of a server-generated first password. Str::password() mixes
     * letters, digits and symbols, so the result clears the production
     * password policy as well as the 12-character API minimum.
     */
    private const GENERATED_PASSWORD_LENGTH = 20;

    /**
     * Resolve a cabinet by primary key with no tenant scope in play, or 404.
     */
    protected function findCabinet(int $id): Cabinet
    {
        /** @var Cabinet|null $cabinet */
        $cabinet = Cabinet::query()
            ->withoutGlobalScopes()
            ->with('license')
            ->whereKey($id)
            ->first();

        if ($cabinet === null) {
            abort(404, 'Cabinet introuvable.');
        }

        return $cabinet;
    }

    /**
     * Build the full detail payload of one clinic. Every collaborator is
     * fetched with an explicit cabinet_id constraint.
     */
    protected function cabinetDetail(Cabinet $cabinet): AdminCabinetResource
    {
        $cabinet->loadMissing(['owner', 'license']);
        $cabinetId = (int) $cabinet->getKey();

        $wilaya = $cabinet->wilaya_code === null
            ? null
            : Wilaya::query()->find($cabinet->wilaya_code);

        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->orderBy('id')
            ->first();

        $isListed = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->where('is_listed', true)
            ->exists();

        return new AdminCabinetResource($cabinet, $wilaya, $doctor, $isListed, [
            'staff' => User::query()->where('cabinet_id', $cabinetId)->count(),
            'patients' => Patient::withoutCabinetScope()->where('cabinet_id', $cabinetId)->count(),
            'appointments' => Appointment::withoutCabinetScope()->where('cabinet_id', $cabinetId)->count(),
        ]);
    }

    /**
     * Create or update the clinic's public-directory row so that mobile
     * discovery shows or hides it. The row is written with an explicit
     * cabinet_id: the trait's creating hook assigns nothing for an actor who
     * belongs to no cabinet.
     */
    protected function setCabinetListed(Cabinet $cabinet, bool $isListed): CabinetPublicProfile
    {
        /** @var CabinetPublicProfile|null $profile */
        $profile = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->first();

        if ($profile === null) {
            $profile = new CabinetPublicProfile(['is_listed' => $isListed]);
            $profile->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

            return $profile;
        }

        $profile->forceFill(['is_listed' => $isListed])->save();

        return $profile;
    }

    /**
     * Attach the wilaya payload, directory flag and owner name that
     * AdminCabinetListItemResource reads, for a whole page of clinics in two
     * lookup queries rather than one pair per row.
     *
     * @param  Collection<int, Cabinet>  $rows
     */
    protected function attachCabinetListAttributes(Collection $rows): void
    {
        $wilayas = Wilaya::query()
            ->whereIn('code', $rows->pluck('wilaya_code')->filter()->unique()->all())
            ->get()
            ->keyBy('code');

        $listed = CabinetPublicProfile::withoutCabinetScope()
            ->whereIn('cabinet_id', $rows->map(static fn (Cabinet $row): int => (int) $row->getKey())->all())
            ->where('is_listed', true)
            ->pluck('cabinet_id')
            ->all();

        $rows->each(static function (Cabinet $row) use ($wilayas, $listed): void {
            /** @var Wilaya|null $wilaya */
            $wilaya = $wilayas->get($row->wilaya_code);

            $row->setAttribute('wilaya_payload', $wilaya === null ? null : [
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ]);
            $row->setAttribute('is_listed_flag', in_array($row->getKey(), $listed, true));
            $row->setAttribute('owner_name', $row->owner?->name);
        });
    }

    /**
     * The password the new account will be created with, plus the plaintext to
     * echo back exactly once when the server invented it.
     *
     * An admin-supplied password is never returned: the admin already knows
     * it, and repeating it would put it in one more log, cache and screenshot.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function resolveInitialPassword(?string $supplied): array
    {
        if (is_string($supplied) && $supplied !== '') {
            return [$supplied, null];
        }

        $generated = Str::password(self::GENERATED_PASSWORD_LENGTH);

        return [$generated, $generated];
    }
}
