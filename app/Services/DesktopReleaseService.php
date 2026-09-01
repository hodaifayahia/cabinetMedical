<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DesktopRelease;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Publishing and serving desktop builds.
 *
 * The trust model matters here, so it is stated once: this server never signs
 * anything. The Tauri build machine holds the private key and emits a detached
 * signature next to the installer. We store that signature and hand it to the
 * shell, which verifies it against the public key compiled into itself. A
 * tampered artifact on this server therefore cannot install, and a stolen
 * database gives an attacker nothing they could sign with.
 */
final class DesktopReleaseService
{
    public const DEFAULT_PLATFORM = 'windows-x86_64';

    public const DEFAULT_CHANNEL = 'stable';

    /**
     * The release both the updater and the public download page should serve.
     */
    public function current(
        string $platform = self::DEFAULT_PLATFORM,
        string $channel = self::DEFAULT_CHANNEL,
    ): ?DesktopRelease {
        return DesktopRelease::query()->current($platform, $channel)->first();
    }

    /**
     * Store an uploaded build and publish it.
     *
     * Publishing is immediate: the moment this returns, the manifest serves the
     * new version and every shell that checks will be offered it. That is what
     * makes the button in the back office feel like "send it to everyone".
     */
    public function publish(
        UploadedFile $installer,
        string $signature,
        string $version,
        ?string $notes,
        User $actor,
        string $platform = self::DEFAULT_PLATFORM,
        string $channel = self::DEFAULT_CHANNEL,
    ): DesktopRelease {
        $version = trim($version);
        $signature = trim($signature);

        if ($signature === '') {
            throw new RuntimeException('La signature de la version est vide.');
        }

        $directory = storage_path('app/private/desktop/releases/'.$this->safeSegment($version));

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier de la version.');
        }

        $filename = $this->safeSegment($installer->getClientOriginalName());
        $installer->move($directory, $filename);

        $fullPath = $directory.DIRECTORY_SEPARATOR.$filename;
        $checksum = hash_file('sha256', $fullPath);

        if ($checksum === false) {
            throw new RuntimeException('Impossible de calculer l’empreinte de l’installateur.');
        }

        return DB::transaction(function () use (
            $version, $channel, $platform, $notes, $filename,
            $installer, $fullPath, $checksum, $signature, $actor,
        ): DesktopRelease {
            $release = DesktopRelease::query()->updateOrCreate(
                [
                    'version' => $version,
                    'platform' => $platform,
                    'channel' => $channel,
                ],
                [
                    'notes' => $notes,
                    'installer_path' => $this->safeSegment($version).'/'.$filename,
                    'installer_name' => $installer->getClientOriginalName(),
                    'installer_size' => (int) filesize($fullPath),
                    'installer_sha256' => $checksum,
                    'signature' => $signature,
                    'published_at' => now(),
                    'published_by_user_id' => $actor->getKey(),
                ],
            );

            AuditLog::record('desktop.release_published', $release, [
                'version' => $release->version,
                'platform' => $release->platform,
                'channel' => $release->channel,
                'sha256' => $release->installer_sha256,
            ], $actor->getKey());

            return $release;
        });
    }

    /**
     * The updater manifest, in the shape tauri-plugin-updater expects.
     *
     * @return array<string, mixed>
     */
    public function manifest(DesktopRelease $release): array
    {
        return [
            'version' => $release->version,
            'notes' => (string) ($release->notes ?? ''),
            'pub_date' => ($release->published_at ?? now())->toIso8601String(),
            'platforms' => [
                $release->platform => [
                    'signature' => $release->signature,
                    'url' => route('desktop.updates.artifact', ['release' => $release->getKey()]),
                ],
            ],
        ];
    }

    /**
     * Keep an uploaded name usable as one path segment, and never let it climb
     * out of the releases directory.
     */
    private function safeSegment(string $value): string
    {
        $value = basename(str_replace('\\', '/', $value));
        $value = preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '';
        $value = trim($value, '.');

        if ($value === '') {
            throw new RuntimeException('Nom de fichier invalide.');
        }

        return Str::limit($value, 120, '');
    }
}
