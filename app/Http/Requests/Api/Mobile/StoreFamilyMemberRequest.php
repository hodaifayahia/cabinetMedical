<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A dependent family profile: demographics are stored inline because the
 * member has no patient account of their own.
 */
class StoreFamilyMemberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'relation' => ['required', 'string', Rule::in(FamilyRelation::values())],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'string', Rule::in(Gender::values())],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'wilaya_code' => ['nullable', 'integer', 'between:1,58'],
            'baladiya_id' => ['nullable', 'integer', 'exists:baladiyas,id'],
        ];
    }
}
