<?php

namespace App\Http\Requests\Api\Mobile\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the platform back office.
 *
 * Every admin payload is closed against privilege escalation: the fields that
 * decide *what* an account is — the platform flag, its roles, its tenant and
 * its approval — can never travel in a request body. A superadmin exists only
 * because `platform:provision-superadmin` created one, and an admin-created
 * account gets its role from the endpoint it was created through, never from
 * the caller. Sending any of these fields fails the request outright (422)
 * instead of being silently dropped, so a mistaken or malicious client is told
 * plainly that nothing happened.
 *
 * The rule is "missing", not "prohibited": Laravel reads "prohibited" as
 * absent OR EMPTY, so a body carrying is_platform_admin: null would sail
 * straight through it, and ConvertEmptyStringsToNull widens that to the empty
 * string too. "missing" fails on presence alone, which is what the barrier
 * described above actually claims.
 */
abstract class AdminFormRequest extends FormRequest
{
    /**
     * Fields no admin request body may ever carry.
     *
     * @var list<string>
     */
    protected const PROHIBITED_FIELDS = [
        'is_platform_admin',
        'role',
        'roles',
        'cabinet_id',
        'approved_at',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    final public function rules(): array
    {
        // Prohibitions are merged last so a subclass cannot relax them.
        return [
            ...$this->adminRules(),
            ...array_fill_keys(static::PROHIBITED_FIELDS, ['missing']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(
            array_map(static fn (string $field): string => $field.'.missing', static::PROHIBITED_FIELDS),
            'Ce champ ne peut pas être fourni : il est déterminé par la plateforme.',
        );
    }

    /**
     * The endpoint's own rules.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract protected function adminRules(): array;
}
