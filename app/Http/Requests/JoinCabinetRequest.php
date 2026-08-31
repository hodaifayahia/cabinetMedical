<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class JoinCabinetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                // Compared case-insensitively so a legacy mixed-case row cannot
                // slip a second account past the check. Two rows differing only
                // in case would make the desktop cabinet sign-in ambiguous and
                // reject both accounts.
                Rule::unique(User::class, 'email')->where(
                    fn ($query) => $query->whereRaw('LOWER(email) = ?', [$this->normalized('email')]),
                ),
            ],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'owner_email' => ['required', 'email', 'max:190'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Fortify canonicalises the username on registration and sign-in, so
        // every stored address is lower case. The join form is not a Fortify
        // route and has to do the same itself, otherwise an owner address
        // typed with a leading capital finds no cabinet on a case-sensitive
        // database such as PostgreSQL.
        $this->merge([
            'email' => $this->normalized('email'),
            'owner_email' => $this->normalized('owner_email'),
        ]);
    }

    private function normalized(string $key): string
    {
        return Str::lower(trim((string) $this->input($key)));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.required' => "L'adresse e-mail du propriétaire du cabinet est requise.",
            'email.unique' => 'Un compte utilise déjà cette adresse e-mail.',
        ];
    }
}
