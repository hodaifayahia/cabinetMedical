<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class StoreDesktopDownloadLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[0-9+().\s-]+$/'],
            'cabinet_name' => ['required', 'string', 'min:2', 'max:160'],
            'specialization' => ['required', 'string', 'min:2', 'max:160'],
            'website' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
            'phone' => trim((string) $this->input('phone')),
            'cabinet_name' => trim((string) $this->input('cabinet_name')),
            'specialization' => trim((string) $this->input('specialization')),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Votre nom complet est requis.',
            'email.required' => 'Votre adresse e-mail est requise.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'phone.required' => 'Votre numéro de téléphone est requis.',
            'phone.regex' => 'Saisissez un numéro de téléphone valide.',
            'cabinet_name.required' => 'Le nom du cabinet est requis.',
            'specialization.required' => 'Votre spécialité médicale est requise.',
        ];
    }
}
