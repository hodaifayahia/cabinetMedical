<?php

namespace App\Http\Requests\Api\Mobile\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Platform back office: show or hide a clinic in the public mobile directory.
 * Only the visibility flag is writable here — the clinic's own staff still own
 * the rest of their public profile through the staff-mobile endpoints.
 */
class UpdateAdminCabinetListingRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function adminRules(): array
    {
        return [
            'is_listed' => ['required', 'boolean'],
        ];
    }
}
