<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff mobile: manage the cabinet's public directory listing. Photo upload
 * is not part of Phase 1 — photos are plain path/URL strings.
 */
class UpdateClinicProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_listed' => ['sometimes', 'boolean'],
            'about' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'baladiya_id' => ['sometimes', 'nullable', 'integer', Rule::exists('baladiyas', 'id')],
            'phones' => ['sometimes', 'nullable', 'array', 'max:3'],
            'phones.*' => ['string', 'regex:/^0[567][0-9]{8}$/'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'photos' => ['sometimes', 'nullable', 'array', 'max:6'],
            'photos.*' => ['string', 'max:2048'],
        ];
    }
}
