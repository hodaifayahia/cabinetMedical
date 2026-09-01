<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\FamilyRelation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to link another patient account to the caller's family circle.
 * The target account must approve the link before it becomes usable.
 */
class LinkFamilyMemberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', 'exists:users,phone'],
            'relation' => ['required', 'string', Rule::in(FamilyRelation::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.exists' => 'Aucun compte patient ne correspond à ce numéro de téléphone.',
        ];
    }
}
