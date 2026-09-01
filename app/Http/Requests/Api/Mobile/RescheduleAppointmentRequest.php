<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff mobile: move an appointment to a new slot. The slot's availability
 * is checked in the controller against the shared availability rules.
 */
class RescheduleAppointmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date', 'after:now'],
        ];
    }
}
