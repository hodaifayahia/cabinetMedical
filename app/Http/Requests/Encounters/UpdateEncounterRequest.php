<?php

namespace App\Http\Requests\Encounters;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason_for_visit' => ['nullable', 'string', 'max:5000'],
            'clinical_examination' => ['nullable', 'string', 'max:5000'],
            'diagnosis_assessment' => ['nullable', 'string', 'max:5000'],
            'treatment_plan' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
