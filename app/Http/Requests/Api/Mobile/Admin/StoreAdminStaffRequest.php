<?php

namespace App\Http\Requests\Api\Mobile\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Platform back office: add a reception account to an existing clinic.
 *
 * The role is not a parameter — this endpoint only ever mints an Assistant —
 * which is why `role` sits in the inherited prohibited list.
 */
class StoreAdminStaffRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function adminRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^0[567][0-9]{8}$/',
                // users.phone is UNIQUE and a receptionist frequently already
                // holds a mobile patient account on the same number: without
                // this the insert dies as a 500 instead of a field error.
                Rule::unique('users', 'phone'),
            ],
            'password' => ['nullable', 'string', 'min:12', 'max:255', Password::default()],
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
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre compte.',
            'password.min' => 'Le mot de passe doit contenir au moins 12 caractères.',
        ];
    }
}
