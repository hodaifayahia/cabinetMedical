<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationEvent;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use Illuminate\Support\Str;
use Throwable;

/**
 * The folder the doctor chose for a second copy of every verified backup:
 * another disk, a USB key or a synchronised folder.
 *
 * The archives themselves always stay in the managed backup directory, which
 * retention, Drive uploads and restores rely on. This class only copies a
 * finished, verified archive there, checks the copy byte for byte (SHA-256),
 * keeps the newest copies and records the outcome for the settings page. A
 * failed copy never turns the backup itself into a failure.
 */
final class BackupCopyDestination
{
    /** Only archives Drclick wrote are ever deleted from the chosen folder. */
    private const MANAGED_COPY_PATTERN = '/\ADrclick-[A-Za-z0-9._-]+\.msbackup\z/';

    private const CHUNK_BYTES = 1024 * 1024;

    public function __construct(private readonly ApplicationSettingService $settings) {}

    public function directory(): ?string
    {
        try {
            $directory = $this->settings->get(Setting::BACKUP_COPY_DIRECTORY);
        } catch (Throwable) {
            return null;
        }

        return is_string($directory) && trim($directory) !== '' ? trim($directory) : null;
    }

    public function keep(): int
    {
        try {
            return max(1, (int) $this->settings->get(Setting::BACKUP_COPY_KEEP));
        } catch (Throwable) {
            return 10;
        }
    }

    /**
     * @return array{status: 'success'|'failed', filename: string|null, path: string|null, message: string|null, at: string}|null
     */
    public function lastResult(): ?array
    {
        try {
            $result = $this->settings->get(Setting::BACKUP_COPY_LAST_RESULT);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($result) || ! in_array($result['status'] ?? null, ['success', 'failed'], true)) {
            return null;
        }

        return [
            'status' => $result['status'],
            'filename' => is_string($result['filename'] ?? null) ? $result['filename'] : null,
            'path' => is_string($result['path'] ?? null) ? $result['path'] : null,
            'message' => is_string($result['message'] ?? null) ? $result['message'] : null,
            'at' => is_string($result['at'] ?? null) ? $result['at'] : now()->toIso8601String(),
        ];
    }

    /**
     * Why $directory cannot hold the copies, in French, or null when it can.
     * A missing folder is created; the check writes and deletes a probe file.
     */
    public function problemWith(string $directory): ?string
    {
        $directory = trim($directory);

        if ($directory === '' || strlen($directory) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $directory) === 1) {
            return 'Indiquez le chemin complet d’un dossier.';
        }

        if (! $this->isAbsolute($directory)) {
            return 'Indiquez un chemin complet, par exemple D:\\Sauvegardes Drclick ou E:\\ pour une clé USB.';
        }

        foreach ($this->protectedRoots() as $root) {
            if ($this->isWithin($directory, $root)) {
                return 'Choisissez un dossier en dehors des données de Drclick : une copie y serait perdue avec le PC.';
            }
        }

        if (is_link($directory)) {
            return 'Ce dossier est un lien symbolique : choisissez le dossier réel.';
        }

        if (! is_dir($directory)) {
            if (file_exists($directory)) {
                return 'Ce chemin désigne un fichier, pas un dossier.';
            }

            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return 'Ce dossier n’existe pas et n’a pas pu être créé. Vérifiez que le disque ou la clé USB est branché.';
            }
        }

        $canonical = realpath($directory);

        if (! is_string($canonical)) {
            return 'Ce dossier n’est pas accessible.';
        }

        foreach ($this->protectedRoots() as $root) {
            if ($this->isWithin($canonical, $root)) {
                return 'Choisissez un dossier en dehors des données de Drclick : une copie y serait perdue avec le PC.';
            }
        }

        $probe = $canonical.DIRECTORY_SEPARATOR.'.drclick-write-test-'.Str::lower(Str::random(8));

        if (@file_put_contents($probe, 'drclick') !== 7) {
            @unlink($probe);

            return 'Drclick ne peut pas écrire dans ce dossier. Choisissez un dossier autorisé en écriture.';
        }

        @unlink($probe);

        return null;
    }

    /**
     * Copy one verified local archive into the chosen folder, then keep only
     * the newest copies there. Never throws.
     *
     * @return array{status: 'copied'|'skipped'|'failed', path: string|null, message: string|null}
     */
    public function copy(BackupRecord $record): array
    {
        $directory = $this->directory();

        if ($directory === null) {
            return ['status' => 'skipped', 'path' => null, 'message' => null];
        }

        $filename = (string) $record->filename;
        $target = null;

        try {
            $problem = $this->problemWith($directory);

            if ($problem !== null) {
                throw new BackupCopyFailed($problem);
            }

            $source = (string) $record->local_path;

            if ($record->status !== 'completed' || $source === '' || ! is_file($source) || is_link($source)
                || preg_match(self::MANAGED_COPY_PATTERN, $filename) !== 1) {
                throw new BackupCopyFailed('La sauvegarde à copier n’est plus disponible sur ce PC.');
            }

            $canonical = (string) realpath($directory);
            $target = $canonical.DIRECTORY_SEPARATOR.$filename;
            $expected = is_string($record->sha256) && preg_match('/\A[a-f0-9]{64}\z/', $record->sha256) === 1
                ? $record->sha256
                : null;
            $this->copyVerified($source, $target, $expected);
            $this->applyRetention($canonical, $filename);
            $this->remember('success', $filename, $target, null);

            return ['status' => 'copied', 'path' => $target, 'message' => null];
        } catch (Throwable $exception) {
            $message = $exception instanceof BackupCopyFailed
                ? $exception->getMessage()
                : 'La copie n’a pas pu être écrite ou vérifiée dans le dossier choisi (disque plein ou débranché ?).';
            $this->remember('failed', $filename, $target, $message);

            try {
                ApplicationEvent::record('BackupCopyFailed', 'warning', context: [
                    'backup_record_id' => $record->getKey(),
                    'error' => 'backup_copy_failed',
                ]);
            } catch (Throwable) {
                // Diagnostics only.
            }

            return ['status' => 'failed', 'path' => $target, 'message' => $message];
        }
    }

    /** Whether a verified copy of $filename sits in the chosen folder. */
    public function holdsCopyOf(string $filename): bool
    {
        $directory = $this->directory();

        if ($directory === null || basename(str_replace('\\', '/', $filename)) !== $filename) {
            return false;
        }

        $path = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$filename;

        return @is_file($path) && ! @is_link($path);
    }

    private function copyVerified(string $source, string $target, ?string $expectedSha256): void
    {
        $partial = $target.'.part-'.Str::lower(Str::random(6));
        $input = @fopen($source, 'rb');
        $output = @fopen($partial, 'xb');

        try {
            if (! is_resource($input) || ! is_resource($output)) {
                throw new BackupCopyFailed('La copie n’a pas pu être créée dans le dossier choisi.');
            }

            $sourceHash = hash_init('sha256');

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new BackupCopyFailed('La sauvegarde n’a pas pu être lue.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($sourceHash, $chunk);
                $offset = 0;

                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));

                    if (! is_int($written) || $written <= 0) {
                        throw new BackupCopyFailed('Le dossier choisi est plein ou n’accepte plus l’écriture.');
                    }

                    $offset += $written;
                }
            }

            fflush($output);
            fclose($output);
            $output = null;
            $sourceSha256 = hash_final($sourceHash);

            if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $sourceSha256)) {
                throw new BackupCopyFailed('La sauvegarde a changé depuis sa vérification : elle n’a pas été copiée.');
            }

            // Read back what reached the disk, not what was sent to it.
            $copySha256 = hash_file('sha256', $partial);

            if (! is_string($copySha256) || ! hash_equals($sourceSha256, $copySha256)) {
                throw new BackupCopyFailed('La copie écrite ne correspond pas à la sauvegarde (support défectueux ?).');
            }

            if (file_exists($target)) {
                $existing = hash_file('sha256', $target);

                if (is_string($existing) && hash_equals($sourceSha256, $existing)) {
                    @unlink($partial);

                    return;
                }

                @unlink($target);
            }

            if (! @rename($partial, $target)) {
                throw new BackupCopyFailed('La copie n’a pas pu être finalisée dans le dossier choisi.');
            }
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }

            @unlink($partial);

            throw $exception;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    /** Keep the newest Drclick copies; never touch anything else in the folder. */
    private function applyRetention(string $directory, string $justCopied): void
    {
        $copies = [];

        foreach (scandir($directory) ?: [] as $entry) {
            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (preg_match(self::MANAGED_COPY_PATTERN, $entry) === 1 && is_file($path) && ! is_link($path)) {
                $copies[$entry] = (int) @filemtime($path);
            }
        }

        // The copy just written always stays, then the newest others.
        unset($copies[$justCopied]);
        uksort($copies, static fn (string $a, string $b): int => [$copies[$b], $b] <=> [$copies[$a], $a]);

        foreach (array_slice(array_keys($copies), max(0, $this->keep() - 1)) as $entry) {
            @unlink($directory.DIRECTORY_SEPARATOR.$entry);
        }
    }

    private function remember(string $status, ?string $filename, ?string $path, ?string $message): void
    {
        try {
            $this->settings->setInternal(Setting::BACKUP_COPY_LAST_RESULT, [
                'status' => $status,
                'filename' => $filename,
                'path' => $path,
                'message' => $message,
                'at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // The copy itself is what matters.
        }
    }

    /** @return list<string> */
    private function protectedRoots(): array
    {
        $roots = [
            (string) config('medismart.backups.managed_directory', storage_path('app/private/backups')),
            storage_path(),
            (string) config('filesystems.disks.local.root', storage_path('app/private')),
            (string) config('filesystems.disks.public.root', storage_path('app/public')),
            base_path(),
        ];
        $database = config('database.connections.sqlite.database');

        if (is_string($database) && $database !== '' && $database !== ':memory:') {
            $roots[] = dirname($database);
        }

        $resolved = [];

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $resolved[] = $root;
            $real = realpath($root);

            if (is_string($real)) {
                $resolved[] = $real;
            }
        }

        return array_values(array_unique($resolved));
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }

    private function isWithin(string $path, string $root): bool
    {
        $normalize = static function (string $value): string {
            $value = rtrim(str_replace('\\', '/', $value), '/');

            return PHP_OS_FAMILY === 'Windows' || preg_match('/\A[A-Za-z]:/', $value) === 1
                ? strtolower($value)
                : $value;
        };
        $path = $normalize($path);
        $root = $normalize($root);

        return $root !== '' && ($path === $root || str_starts_with($path.'/', $root.'/'));
    }
}
