<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class DesktopDownloadService
{
    /**
     * @return array{
     *     available: bool,
     *     url: string|null,
     *     label: string,
     *     reason: string|null
     * }
     */
    public function sharedProps(): array
    {
        if ($this->hasDownload()) {
            return [
                'available' => true,
                'url' => Route::has('desktop.download') ? route('desktop.download') : null,
                'label' => 'Télécharger l’app desktop',
                'reason' => null,
            ];
        }

        return [
            'available' => false,
            'url' => null,
            'label' => 'Télécharger l’app desktop',
            'reason' => 'Ajoutez MEDISMART_DESKTOP_DOWNLOAD_URL ou placez un installateur lisible dans storage/app/private/desktop.',
        ];
    }

    public function hasDownload(): bool
    {
        return $this->externalUrl() !== null || $this->localInstallerPath() !== null;
    }

    public function externalUrl(): ?string
    {
        $url = config('medismart.desktop_download.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    public function localInstallerPath(): ?string
    {
        $candidate = config('medismart.desktop_download.installer_path');

        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);
        $path = Str::startsWith($candidate, DIRECTORY_SEPARATOR)
            ? $candidate
            : storage_path('app/private/desktop/'.$candidate);

        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            return null;
        }

        if (! $this->hasAllowedInstallerExtension($realPath)) {
            return null;
        }

        if (! Str::startsWith($candidate, DIRECTORY_SEPARATOR)) {
            $basePath = realpath(storage_path('app/private/desktop'));

            if ($basePath === false || ! Str::startsWith($realPath, $basePath.DIRECTORY_SEPARATOR)) {
                return null;
            }
        }

        return $realPath;
    }

    private function hasAllowedInstallerExtension(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ['exe', 'msi', 'zip', 'dmg', 'appimage'],
            true,
        );
    }
}
