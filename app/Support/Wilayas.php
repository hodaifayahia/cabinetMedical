<?php

namespace App\Support;

/**
 * Read-only helper around the official Algerian wilaya catalogue defined in
 * config/wilayas.php. Codes range from 1 to 58.
 */
final class Wilayas
{
    public const MIN = 1;

    public const MAX = 58;

    /**
     * The wilaya catalogue keyed by integer code.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $catalogue = [];

        foreach ((array) config('wilayas', []) as $code => $name) {
            $catalogue[(int) $code] = (string) $name;
        }

        return $catalogue;
    }

    /**
     * Wilaya options shaped for a select control fed to the front-end.
     *
     * @return list<array{code: int, name: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }

    public static function exists(?int $code): bool
    {
        return $code !== null && array_key_exists($code, self::all());
    }

    public static function name(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::all()[$code] ?? null;
    }

    /**
     * Human-readable label combining the padded code and the name, e.g.
     * "16 - Alger". Falls back to the raw code when unknown.
     */
    public static function label(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $name = self::name($code);

        if ($name === null) {
            return (string) $code;
        }

        return sprintf('%02d - %s', $code, $name);
    }
}
