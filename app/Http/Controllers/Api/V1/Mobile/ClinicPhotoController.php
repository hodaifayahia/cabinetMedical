<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ClinicProfileResource;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use App\Support\ClinicPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Photos of the clinic's public listing, uploaded from the staff mobile app.
 * Each action takes effect at once (no form Save) and answers with the whole
 * profile, so the app shows exactly what patients now see. Same permission
 * as editing the listing: configuration.branding.manage.
 */
class ClinicPhotoController extends Controller
{
    /** `photo` (image, ≤ 10 MB) is added at the end, or replaces the photo at `replace`. */
    public function store(Request $request): ClinicProfileResource
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'replace' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $profile = $this->profileFor($request);
        $photos = array_values($profile->photos ?? []);
        $replace = isset($data['replace']) ? (int) $data['replace'] : null;

        if ($replace !== null && ! array_key_exists($replace, $photos)) {
            throw ValidationException::withMessages(['replace' => ['Cette photo n\'existe plus.']]);
        }
        if ($replace === null && count($photos) >= ClinicPhotos::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photo' => ['Vous avez déjà '.ClinicPhotos::MAX_PHOTOS.' photos. Supprimez-en une d\'abord.'],
            ]);
        }

        try {
            $path = ClinicPhotos::store($request->file('photo'), (int) $profile->cabinet_id);
        } catch (Throwable) {
            throw ValidationException::withMessages(['photo' => ['Cette image ne peut pas être lue. Choisissez une photo JPEG ou PNG.']]);
        }

        $previous = null;
        if ($replace !== null) {
            $previous = $photos[$replace];
            $photos[$replace] = $path;
        } else {
            $photos[] = $path;
        }

        try {
            $this->save($profile, $photos);
        } catch (Throwable $exception) {
            ClinicPhotos::delete([$path]);

            throw $exception;
        }

        if ($previous !== null) {
            ClinicPhotos::delete([$previous]);
        }

        return new ClinicProfileResource($profile->refresh());
    }

    public function destroy(Request $request, int $index): ClinicProfileResource
    {
        $profile = $this->profileFor($request);
        $photos = array_values($profile->photos ?? []);
        abort_unless(array_key_exists($index, $photos), 404);

        $removed = $photos[$index];
        unset($photos[$index]);
        $this->save($profile, array_values($photos));
        ClinicPhotos::delete([$removed]);

        return new ClinicProfileResource($profile->refresh());
    }

    /** Moves the photo at `$index` to the front: the first photo is the listing's cover. */
    public function cover(Request $request, int $index): ClinicProfileResource
    {
        $profile = $this->profileFor($request);
        $photos = array_values($profile->photos ?? []);
        abort_unless(array_key_exists($index, $photos), 404);

        $chosen = $photos[$index];
        unset($photos[$index]);
        $this->save($profile, [$chosen, ...array_values($photos)]);

        return new ClinicProfileResource($profile->refresh());
    }

    /** @param  list<string>  $photos */
    private function save(CabinetPublicProfile $profile, array $photos): void
    {
        DB::transaction(function () use ($profile, $photos): void {
            $profile->photos = $photos;
            $profile->save();
        });
    }

    /** Same lookup as ClinicProfileController: keyed on the caller's cabinet, created on first use. */
    private function profileFor(Request $request): CabinetPublicProfile
    {
        /** @var User $user */
        $user = $request->user();
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
