<?php

namespace App\Http\Requests\Cabinet;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RedeemHostedLicenseCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->cabinet_id !== null
            && $user->cabinet?->owner_user_id === $user->getKey();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'license_code' => ['required', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'license_code.required' => 'Saisissez le code de licence fourni par DrClickDz.',
            'license_code.max' => 'Ce code de licence est invalide.',
        ];
    }
}
