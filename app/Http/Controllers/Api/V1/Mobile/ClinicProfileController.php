<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\UpdateClinicProfileRequest;
use App\Http\Resources\Mobile\ClinicProfileResource;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The cabinet's public directory listing, managed from the staff mobile app.
 * Reading is open to any cabinet member; writing sits behind the
 * configuration.branding.manage permission middleware.
 */
class ClinicProfileController extends Controller
{
    public function show(Request $request): ClinicProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ClinicProfileResource($this->profileFor($user));
    }

    public function update(UpdateClinicProfileRequest $request): ClinicProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->profileFor($user);
        $profile->fill($request->validated());
        $profile->save();

        return new ClinicProfileResource($profile->refresh());
    }

    /**
     * The caller's cabinet profile, or a fresh unsaved one when the cabinet
     * has never been listed. The lookup is keyed on the caller's cabinet_id
     * explicitly — never on the global scope alone, which is inert for
     * null-cabinet accounts and bypassed for platform admins (both rejected
     * upstream by the mobile.staff.cabinet middleware).
     */
    private function profileFor(User $user): CabinetPublicProfile
    {
        abort_if($user->cabinet_id === null, 403);

        $profile = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $user->cabinet_id)
            ->first() ?? new CabinetPublicProfile;

        if (! $profile->exists) {
            $profile->setAttribute('cabinet_id', $user->cabinet_id);
        }

        return $profile;
    }
}
