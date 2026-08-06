<?php

namespace App\Backups;

final class RestoreLifecycleLock
{
    private const FILENAME = 'restore-lifecycle.lock';

    public function tryAcquire(): ?RestoreLifecycleLease
    {
        $directory = storage_path('app/private');
        $this->ensurePrivateDirectory($directory);
        $path = $directory.DIRECTORY_SEPARATOR.self::FILENAME;

        if (is_link($path)) {
            throw new BackupArchiveException('The restore lifecycle lock path is unsafe.');
        }

        $handle = @fopen($path, 'c+b');

        if (! is_resource($handle)) {
            throw new BackupArchiveException('The restore lifecycle lock is unavailable.');
        }

        try {
            if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
                throw new BackupArchiveException('The restore lifecycle lock could not be secured.');
            }

            $this->assertHandleIdentity($path, $directory, $handle);

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return null;
            }

            $this->assertHandleIdentity($path, $directory, $handle);

            return new RestoreLifecycleLease($handle);
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($exception instanceof BackupArchiveException) {
                throw $exception;
            }

            throw new BackupArchiveException('The restore lifecycle lock could not be validated.');
        }
    }

    /** @param resource $handle */
    private function assertHandleIdentity(string $path, string $directory, mixed $handle): void
    {
        clearstatcache(true, $path);
        $root = realpath($directory);
        $resolved = realpath($path);
        $stream = fstat($handle);
        $node = @lstat($path);

        if (! is_string($root)
            || ! is_string($resolved)
            || dirname($resolved) !== rtrim($root, DIRECTORY_SEPARATOR)
            || basename($resolved) !== self::FILENAME
            || is_link($path)
            || ! is_array($stream)
            || ! is_array($node)
            || ($stream['mode'] & 0170000) !== 0100000
            || ($node['mode'] & 0170000) !== 0100000
            || $stream['nlink'] !== 1
            || $node['nlink'] !== 1
            || $stream['dev'] !== $node['dev']
            || $stream['ino'] !== $node['ino']) {
            throw new BackupArchiveException('The restore lifecycle lock identity is invalid.');
        }
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && (! @mkdir($directory, 0700, true) && ! is_dir($directory)))
            || ! is_writable($directory)) {
            throw new BackupArchiveException('The restore lifecycle directory is unavailable.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new BackupArchiveException('The restore lifecycle directory could not be secured.');
        }
    }
}
