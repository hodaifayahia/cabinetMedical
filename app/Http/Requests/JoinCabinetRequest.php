<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class JoinCabinetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'owner_email' => ['required', 'email', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.required' => "L'adresse e-mail du propriétaire du cabinet est requise.",
            'email.unique' => 'Un compte utilise déjà cette adresse e-mail.',
        ];
    }
}
