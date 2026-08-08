<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\DesktopPinService;
use Illuminate\Foundation\Http\FormRequest;

class LoginWithDesktopPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'device_token.required' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'device_token.string' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'device_token.min' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'device_token.max' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'device_token.regex' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'pin.required' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'pin.string' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
            'pin.regex' => DesktopPinService::INVALID_CREDENTIAL_MESSAGE,
        ];
    }
}
