<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\UpdateProfileRequest;
use App\Http\Resources\Mobile\MobileProfileResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated patient's own account profile. Guarded by
 * `mobile.patient`, and every write targets the caller's own rows only —
 * the BelongsToCabinet scope is inert for cabinet-less patient accounts.
 */
class ProfileController extends Controller
{
    public function show(Request $request): MobileProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MobileProfileResource($user->load('patientProfile.baladiya'));
    }

    public function update(UpdateProfileRequest $request): MobileProfileResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data): void {
            if (array_key_exists('email', $data)) {
                $user->email = $data['email'];
                $user->save();
            }

            $demographics = Arr::only($data, [
                'first_name',
                'last_name',
                'gender',
                'date_of_birth',
                'place_of_birth',
                'wilaya_code',
                'baladiya_id',
            ]);

            if ($demographics !== []) {
                $user->patientProfile()->firstOrNew()->fill($demographics)->save();
            }
        });

        return new MobileProfileResource($user->refresh()->load('patientProfile.baladiya'));
    }
}
