<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\DesktopPinService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class EnrollDesktopPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(DesktopPinService::class)->canEnroll($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_token' => [
                'required',
                'string',
                'min:32',
                'max:255',
                'regex:/\A[A-Za-z0-9_-]+\z/D',
            ],
            'pin' => ['required', 'string', 'confirmed', 'regex:/\A[0-9]{4}\z/D'],
            'pin_confirmation' => ['required', 'string'],
            'device_name' => [
                'required',
                'string',
                'min:2',
                'max:120',
                'different:device_token',
                'different:pin',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('device_name'))) {
            $this->merge(['device_name' => trim($this->input('device_name'))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'device_token.regex' => 'L’identifiant sécurisé de cet appareil est invalide.',
            'pin.confirmed' => 'La confirmation du code PIN ne correspond pas.',
            'pin.regex' => 'Le code PIN doit contenir exactement 4 chiffres.',
            'device_name.different' => 'Le nom de l’appareil est invalide.',
        ];
    }
}
