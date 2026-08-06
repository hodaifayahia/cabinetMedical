<?php

namespace App\Backups;

final class BackupArchivePath
{
    private const MAXIMUM_PATH_BYTES = 2048;

    private const MAXIMUM_SEGMENT_BYTES = 255;

    private const MAXIMUM_DEPTH = 32;

    /**
     * Validate an archive entry for eventual extraction on both POSIX and
     * Windows. Creation and verification share this policy so an archive that
     * we publish can never require unsafe path handling during restore.
     */
    public static function assertSafe(string $entry): void
    {
        if ($entry === ''
            || strlen($entry) > self::MAXIMUM_PATH_BYTES
            || preg_match('//u', $entry) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $entry) === 1
            || preg_match('/[<>"|?*]/u', $entry) === 1
            || str_contains($entry, "\0")
            || str_contains($entry, '\\')
            || str_starts_with($entry, '/')
            || str_contains($entry, ':')) {
            throw new BackupArchiveException('The backup contains an unsafe entry name.');
        }

        $segments = explode('/', $entry);

        if (count($segments) > self::MAXIMUM_DEPTH) {
            throw new BackupArchiveException('The backup contains an unsafe entry name.');
        }

        foreach ($segments as $segment) {
            if ($segment === ''
                || strlen($segment) > self::MAXIMUM_SEGMENT_BYTES
                || $segment === '.'
                || $segment === '..'
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
                || self::isReservedWindowsName($segment)) {
                throw new BackupArchiveException('The backup contains an unsafe entry name.');
            }
        }
    }

    /**
     * Key used to detect names that collide on common restore filesystems.
     */
    public static function portableKey(string $entry): string
    {
        $normalized = $entry;

        if (class_exists(\Normalizer::class)) {
            $unicodeNormalized = \Normalizer::normalize($entry, \Normalizer::FORM_C);

            if (is_string($unicodeNormalized)) {
                $normalized = $unicodeNormalized;
            }
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }

    private static function isReservedWindowsName(string $segment): bool
    {
        $basename = strtoupper((string) pathinfo($segment, PATHINFO_FILENAME));

        return in_array($basename, [
            'CON',
            'CONIN$',
            'CONOUT$',
            'PRN',
            'AUX',
            'NUL',
            'COM1',
            'COM2',
            'COM3',
            'COM4',
            'COM5',
            'COM6',
            'COM7',
            'COM8',
            'COM9',
            'LPT1',
            'LPT2',
            'LPT3',
            'LPT4',
            'LPT5',
            'LPT6',
            'LPT7',
            'LPT8',
            'LPT9',
        ], true);
    }
}
