<?php

namespace App\Casts;

use App\Enums\Gender;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reads `patients.gender` without trusting the column to hold a current value.
 *
 * The column is an unconstrained varchar, so it still holds values from before
 * Gender was trimmed to male/female ('other', 'undisclosed', and — because
 * SQLite collates BINARY — any casing of them). Laravel's built-in enum cast
 * calls Gender::from(), which throws ValueError on those rows and would 500 the
 * patient index, show, and edit pages.
 *
 * Reading therefore degrades to null, and deliberately does NOT rewrite the
 * stored value: retiring an option is a display change and must not silently
 * destroy a clinic's records. Writing stays strict, so no new unrepresentable
 * value can enter the column.
 *
 * This mirrors the defensive read already used for incoming sync payloads in
 * {@see \App\Services\Sync\PatientResolver}.
 *
 * @implements CastsAttributes<Gender|null, Gender|string|null>
 */
final class RetiredTolerantGender implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Gender
    {
        if ($value === null || $value === '') {
            return null;
        }

        // tryFrom, not from: a retired value reads as "unknown", not a crash.
        return Gender::tryFrom((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Gender) {
            return $value->value;
        }

        $gender = Gender::tryFrom((string) $value);

        if ($gender === null) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid gender.', (string) $value),
            );
        }

        return $gender->value;
    }
}
