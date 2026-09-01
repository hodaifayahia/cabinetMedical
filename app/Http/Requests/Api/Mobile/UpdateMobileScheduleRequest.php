<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\Weekday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff mobile: replace the doctor's weekly working hours. Unlike the web
 * editor, a day may hold up to three non-overlapping ranges (e.g. a morning
 * and an evening session). Days absent from the payload become closed.
 */
class UpdateMobileScheduleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'distinct', Rule::in(Weekday::values())],
            'days.*.ranges' => ['present', 'array', 'max:3'],
            'days.*.ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.ends_at' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.slot_duration' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $days */
            $days = $this->input('days', []);

            foreach ($days as $dayIndex => $day) {
                $ranges = is_array($day['ranges'] ?? null) ? $day['ranges'] : [];

                $this->validateRangeBounds($validator, $dayIndex, $ranges);
                $this->validateRangesDoNotOverlap($validator, $dayIndex, $ranges);
            }
        });
    }

    /**
     * @param  array<int, mixed>  $ranges
     */
    private function validateRangeBounds(Validator $validator, int $dayIndex, array $ranges): void
    {
        foreach ($ranges as $rangeIndex => $range) {
            [$start, $end] = $this->bounds($range);

            if ($start !== null && $end !== null && $end <= $start) {
                $validator->errors()->add(
                    "days.{$dayIndex}.ranges.{$rangeIndex}.ends_at",
                    "L'heure de fin doit être postérieure à l'heure de début.",
                );
            }
        }
    }

    /**
     * @param  array<int, mixed>  $ranges
     */
    private function validateRangesDoNotOverlap(Validator $validator, int $dayIndex, array $ranges): void
    {
        $count = count($ranges);

        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                [$aStart, $aEnd] = $this->bounds($ranges[$a]);
                [$bStart, $bEnd] = $this->bounds($ranges[$b]);

                if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
                    continue;
                }

                if ($aStart < $bEnd && $bStart < $aEnd) {
                    $validator->errors()->add(
                        "days.{$dayIndex}.ranges",
                        'Les plages horaires du même jour ne doivent pas se chevaucher.',
                    );

                    return;
                }
            }
        }
    }

    /**
     * The H:i boundaries of a range, or nulls when malformed. H:i strings
     * compare correctly as plain strings.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function bounds(mixed $range): array
    {
        if (! is_array($range)) {
            return [null, null];
        }

        $start = $range['starts_at'] ?? null;
        $end = $range['ends_at'] ?? null;

        return [
            is_string($start) && preg_match('/^\d{2}:\d{2}$/', $start) === 1 ? $start : null,
            is_string($end) && preg_match('/^\d{2}:\d{2}$/', $end) === 1 ? $end : null,
        ];
    }
}
