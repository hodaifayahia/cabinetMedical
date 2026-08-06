<?php

namespace App\Http\Requests\Appointments;

use App\Enums\Weekday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorScheduleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $min = (int) config('clinic.appointments.min_duration', 5);
        $max = (int) config('clinic.appointments.max_duration', 240);

        return [
            'days' => ['required', 'array', 'min:1'],
            'days.*.day_of_week' => ['required', 'integer', Rule::in(Weekday::values())],
            'days.*.is_working' => ['required', 'boolean'],
            'days.*.starts_at' => ['nullable', 'required_if:days.*.is_working,true', 'date_format:H:i'],
            'days.*.ends_at' => ['nullable', 'required_if:days.*.is_working,true', 'date_format:H:i'],
            'days.*.slot_duration' => ['nullable', 'integer', "min:{$min}", "max:{$max}"],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $days */
            $days = $this->input('days', []);

            foreach ($days as $index => $day) {
                if (empty($day['is_working'])) {
                    continue;
                }

                $start = $day['starts_at'] ?? null;
                $end = $day['ends_at'] ?? null;

                if (is_string($start) && is_string($end) && $start !== '' && $end !== '' && $end <= $start) {
                    $validator->errors()->add("days.{$index}.ends_at", 'The end time must be after the start time.');
                }
            }
        });
    }
}
