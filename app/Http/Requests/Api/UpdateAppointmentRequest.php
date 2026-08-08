<?php

namespace App\Http\Requests\Api;

use App\Enums\AppointmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reception_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'prestation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(array_map(
                static fn (AppointmentStatus $status): string => $status->value,
                AppointmentStatus::cases(),
            ))],
            'cancellation_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
