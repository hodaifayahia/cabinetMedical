<?php

namespace App\Http\Requests\Appointments;

use App\Enums\AppointmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'starts_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reception_notes' => ['nullable', 'string', 'max:2000'],
            'prestation' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(AppointmentStatus::creatableValues())],
        ];
    }
}
