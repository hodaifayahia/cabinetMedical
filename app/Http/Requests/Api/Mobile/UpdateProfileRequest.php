<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\Gender;
use App\Models\Baladiya;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the authenticated patient's own profile. The phone
 * number is the account's identity anchor and is deliberately absent —
 * it is not updatable in Phase 1.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'gender' => ['sometimes', 'required', 'string', Rule::in(Gender::values())],
            'date_of_birth' => ['sometimes', 'required', 'date', 'after:1900-01-01', 'before:today'],
            'place_of_birth' => ['sometimes', 'nullable', 'string', 'max:150'],
            'wilaya_code' => ['sometimes', 'required', 'integer', 'between:1,99', 'exists:wilayas,code'],
            'baladiya_id' => ['sometimes', 'nullable', 'integer', Rule::exists('baladiyas', 'id'), $this->baladiyaBelongsToWilaya()],
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->getKey()),
            ],
        ];
    }

    /**
     * The commune must belong to the wilaya being submitted, or to the
     * wilaya already stored on the profile when none is submitted.
     */
    protected function baladiyaBelongsToWilaya(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $wilayaCode = $this->input('wilaya_code')
                ?? $this->user()?->patientProfile?->wilaya_code;

            $belongs = $wilayaCode !== null && Baladiya::query()
                ->whereKey($value)
                ->where('wilaya_code', (int) $wilayaCode)
                ->exists();

            if (! $belongs) {
                $fail("La commune sélectionnée n'appartient pas à la wilaya choisie.");
            }
        };
    }
}
