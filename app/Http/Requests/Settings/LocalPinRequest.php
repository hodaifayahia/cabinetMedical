<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class LocalPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'confirmed', 'regex:/\A[0-9]{6,12}\z/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pin.required' => 'Saisissez un code PIN.',
            'pin.confirmed' => 'La confirmation du code PIN ne correspond pas.',
            'pin.regex' => 'Le code PIN doit contenir entre 6 et 12 chiffres.',
        ];
    }
}
