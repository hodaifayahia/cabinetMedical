<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePatientAppointmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', 'exists:doctor_profiles,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'family_member_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
