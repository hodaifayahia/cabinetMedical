<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class DesktopCabinetLoginRequest extends FormRequest
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
            'owner_email' => ['required', 'email', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner_email' => Str::lower(trim((string) $this->input('owner_email'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.required' => 'L’adresse e-mail du propriétaire du cabinet est requise.',
            'email.required' => 'Votre adresse e-mail est requise.',
        ];
    }
}
