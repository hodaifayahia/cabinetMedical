<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\UpdateClinicProfileRequest;
use App\Http\Resources\Mobile\ClinicProfileResource;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use App\Support\ClinicPhotos;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $data = $request->validated();
        $before = array_values($profile->photos ?? []);

        if (array_key_exists('photos', $data)) {
            $data['photos'] = $this->keptPhotos($before, $data['photos'] ?? []);
        }

        $profile->fill($data);
        $profile->save();

        if (array_key_exists('photos', $data)) {
            ClinicPhotos::delete(array_values(array_diff($before, $data['photos'])));
        }

        return new ClinicProfileResource($profile->refresh());
    }

    /**
     * Maps the photos sent back (stored values or the links the API handed
     * out) onto what is stored. Anything else is a new link, which is no
     * longer accepted: photos are uploaded from the phone.
     *
     * @param  list<string>  $stored
     * @param  array<int, string>  $sent
     * @return list<string>
     */
    private function keptPhotos(array $stored, array $sent): array
    {
        $byUrl = [];
        foreach ($stored as $value) {
            $byUrl[$value] = $value;
            $byUrl[ClinicPhotos::url($value)] = $value;
        }

        $kept = [];
        foreach (array_values($sent) as $index => $value) {
            if (! isset($byUrl[$value])) {
                throw ValidationException::withMessages([
                    "photos.{$index}" => ['Ajoutez les photos depuis le téléphone : les liens ne sont plus acceptés.'],
                ]);
            }
            $kept[] = $byUrl[$value];
        }

        return array_values(array_unique($kept));
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
