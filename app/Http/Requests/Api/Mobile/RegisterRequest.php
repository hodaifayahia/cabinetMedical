<?php

namespace App\Http\Requests\Api\Mobile;

use App\Enums\Gender;
use App\Models\Baladiya;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public mobile registration. Only ever creates PATIENT accounts: every
 * role-ish or tenant-ish field is hard-rejected so a crafted payload can
 * never escalate itself into a staff or admin account.
 */
class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^0[567][0-9]{8}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:8'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'string', Rule::in(Gender::values())],
            'date_of_birth' => ['required', 'date', 'after:1900-01-01', 'before:today'],
            'wilaya_code' => ['required', 'integer', 'between:1,58'],
            'baladiya_id' => ['nullable', 'integer', Rule::exists('baladiyas', 'id'), $this->baladiyaBelongsToWilaya()],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'terms_accepted' => ['required', 'accepted'],
            'device_name' => ['nullable', 'string', 'max:255'],
            // Never trust the client's role (non-negotiable rule #3).
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'role_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'cabinet_id' => ['prohibited'],
            'approved_at' => ['prohibited'],
        ];
    }

    /**
     * The chosen commune must belong to the chosen wilaya.
     */
    protected function baladiyaBelongsToWilaya(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $belongs = Baladiya::query()
                ->whereKey($value)
                ->where('wilaya_code', (int) $this->input('wilaya_code'))
                ->exists();

            if (! $belongs) {
                $fail("La commune sélectionnée n'appartient pas à la wilaya choisie.");
            }
        };
    }
}
