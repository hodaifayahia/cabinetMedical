<?php

namespace App\Http\Requests\Encounters;

use Illuminate\Foundation\Http\FormRequest;

class CreateAmendmentRequest extends FormRequest
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
            'amendment_reason' => ['required', 'string', 'max:1000'],
            'reason_for_visit' => ['nullable', 'string', 'max:5000'],
            'clinical_examination' => ['nullable', 'string', 'max:5000'],
            'diagnosis_assessment' => ['nullable', 'string', 'max:5000'],
            'treatment_plan' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
