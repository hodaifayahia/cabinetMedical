<?php

namespace App\Http\Requests\Settings;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

final class IdleLockUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::SETTINGS_MANAGE->value) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idle_lock_minutes' => app(ApplicationSettingRegistry::class)
                ->get(ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES)
                ->validationRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idle_lock_minutes.required' => 'Indiquez une durée de verrouillage.',
            'idle_lock_minutes.integer' => 'La durée doit être un nombre entier de minutes.',
            'idle_lock_minutes.min' => 'La durée de verrouillage est trop courte.',
            'idle_lock_minutes.max' => 'La durée de verrouillage dépasse la limite autorisée.',
        ];
    }
}
