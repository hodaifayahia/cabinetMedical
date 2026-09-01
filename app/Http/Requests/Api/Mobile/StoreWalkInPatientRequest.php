<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\Gender;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff mobile: register a walk-in patient at the desk. The phone is the
 * dedup key — the controller returns the existing cabinet dossier when one
 * already carries this number.
 */
class StoreWalkInPatientRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^0[567][0-9]{8}$/'],
            'gender' => ['nullable', 'string', Rule::in(Gender::values())],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'wilaya_code' => ['nullable', 'integer', 'between:1,58'],
            'baladiya_id' => ['nullable', 'integer', Rule::exists('baladiyas', 'id')],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
        ];
    }
}
