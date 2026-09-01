<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff mobile: register a closure. All-day closures (the default, matching
 * the web editor) may start and end on the same date; a partial-day closure
 * must end strictly after it starts.
 */
class StoreMobileTimeOffRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:150'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_all_day', true)) {
                return;
            }

            $start = $this->input('starts_at');
            $end = $this->input('ends_at');

            if (is_string($start) && is_string($end) && strtotime($end) !== false
                && strtotime($start) !== false && strtotime($end) <= strtotime($start)) {
                $validator->errors()->add(
                    'ends_at',
                    'La fin doit être postérieure au début pour une absence partielle.',
                );
            }
        });
    }
}
