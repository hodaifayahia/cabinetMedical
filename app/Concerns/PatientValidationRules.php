<?php

namespace App\Concerns;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait PatientValidationRules
{
    /**
     * Validation rules shared by patient create and update requests.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function patientRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'secondary_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],
            'allergies' => ['nullable', 'string', 'max:5000'],
            'antecedents_medical' => ['nullable', 'string', 'max:5000'],
            'antecedents_surgical' => ['nullable', 'string', 'max:5000'],
            'antecedents_family' => ['nullable', 'string', 'max:5000'],
            'antecedents_other' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
