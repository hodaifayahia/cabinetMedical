<?php

namespace App\Http\Requests\Api\Mobile\Admin;

use App\Enums\FacilityType;
use App\Support\Wilayas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Platform back office: create a clinic together with its doctor owner.
 *
 * The rules mirror web self-registration (same specialty catalogue, same
 * wilaya range) with the mobile Algerian phone format the app already
 * enforces everywhere else. The password is optional: when it is absent the
 * controller generates a strong one and returns it exactly once; when it is
 * supplied it must clear the same strength policy web registration applies.
 */
class StoreAdminCabinetRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function adminRules(): array
    {
        return [
            'cabinet_name' => ['required', 'string', 'min:2', 'max:255'],
            'specialization' => ['required', 'string', 'min:2', 'max:150'],
            'wilaya_code' => [
                'required',
                'integer',
                'between:'.Wilayas::MIN.','.Wilayas::CODE_MAX,
                Rule::exists('wilayas', 'code'),
            ],
            'doctor_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^0[567][0-9]{8}$/'],
            // min:12 is the API floor from the spec; Password::default()
            // adds the platform's production strength policy on top, so an
            // admin-created clinic owner is never weaker than the same account
            // created through web self-registration.
            'password' => ['nullable', 'string', 'min:12', 'max:255', Password::default()],
            'activate' => ['sometimes', 'boolean'],
            'is_listed' => ['sometimes', 'boolean'],
            'facility_type' => ['sometimes', 'string', Rule::enum(FacilityType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'phone.regex' => 'Saisissez un numéro de téléphone algérien valide (0X XX XX XX XX).',
            'password.min' => 'Le mot de passe doit contenir au moins 12 caractères.',
        ];
    }
}
