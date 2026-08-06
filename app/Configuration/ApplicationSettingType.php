<?php

namespace App\Configuration;

enum ApplicationSettingType: string
{
    case STRING = 'string';
    case BOOLEAN = 'boolean';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case JSON = 'json';

    /**
     * @return list<string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::STRING => ['string'],
            self::BOOLEAN => ['boolean'],
            self::INTEGER => ['integer'],
            self::FLOAT => ['numeric'],
            self::JSON => ['array'],
        };
    }

    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::STRING => trim((string) $value),
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            self::INTEGER => (int) $value,
            self::FLOAT => (float) $value,
            self::JSON => $value,
        };
    }
}
