<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use LogicException;

#[Fillable(['key', 'encrypted_value', 'plain_value', 'type', 'group'])]
#[Hidden(['encrypted_value'])]
class ApplicationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'encrypted_value' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $setting): void {
            if ($setting->encrypted_value !== null && $setting->plain_value !== null) {
                throw new LogicException('An application setting cannot contain both plain and encrypted values.');
            }
        });
    }

    public static function putValue(
        string $key,
        mixed $value,
        bool $encrypted = false,
        string $type = 'string',
        string $group = 'general',
    ): self {
        $serialized = self::serializeValue($value, $type);

        return static::query()->updateOrCreate(
            ['key' => $key],
            [
                'encrypted_value' => $encrypted ? $serialized : null,
                'plain_value' => $encrypted ? null : $serialized,
                'type' => $type,
                'group' => $group,
            ],
        );
    }

    public static function valueFor(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        $value = $setting->encrypted_value ?? $setting->plain_value;

        if ($value === null) {
            return $default;
        }

        return self::deserializeValue($value, $setting->type);
    }

    private static function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) ((int) $value),
            'float' => (string) ((float) $value),
            'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private static function deserializeValue(string $value, string $type): mixed
    {
        try {
            return match ($type) {
                'boolean' => $value === '1',
                'integer' => (int) $value,
                'float' => (float) $value,
                'json' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
                default => $value,
            };
        } catch (JsonException) {
            return null;
        }
    }
}
