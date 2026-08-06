<?php

namespace App\Http\Requests\Encounters;

use Illuminate\Foundation\Http\FormRequest;

class StoreEncounterRequest extends FormRequest
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
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'occurred_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
