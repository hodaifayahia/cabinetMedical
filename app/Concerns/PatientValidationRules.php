<?php

namespace App\Concerns;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait PatientValidationRules
{
    /** Longest free text accepted in one medical-history field. */
    public const PATIENT_HISTORY_MAX = 10000;

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
            ...$this->patientDossierRules(),
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Social and medical-history fields, shared by the patient form and the
     * consultation workspace so both accept exactly the same values.
     *
     * @return array<string, array<mixed>>
     */
    protected function patientDossierRules(): array
    {
        return [
            'marital_status' => ['nullable', 'string', 'max:30'],
            'profession' => ['nullable', 'string', 'max:100'],
            'smoking_status' => ['nullable', 'string', 'max:30'],
            'referred_by' => ['nullable', 'string', 'max:150'],
            'allergies' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
            'antecedents_medical' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
            'antecedents_surgical' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
            'antecedents_family' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
            'antecedents_gyneco' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
            'antecedents_other' => ['nullable', 'string', 'max:'.self::PATIENT_HISTORY_MAX],
        ];
    }
}
