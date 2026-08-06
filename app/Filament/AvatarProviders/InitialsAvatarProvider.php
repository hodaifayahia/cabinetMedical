<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->implode('');

        if ($initials === '') {
            $initials = '?';
        }

        $background = $this->colorFor($name === '' ? '?' : $name);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            .'<rect width="96" height="96" fill="'.$background.'"/>'
            .'<text x="50%" y="52%" fill="#FFFFFF" font-family="Arial, Helvetica, sans-serif" '
            .'font-size="40" font-weight="600" text-anchor="middle" dominant-baseline="central">'
            .htmlspecialchars($initials, ENT_QUOTES).'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function colorFor(string $seed): string
    {
        $palette = ['#0f766e', '#1d4ed8', '#7c3aed', '#be123c', '#b45309', '#0369a1', '#4d7c0f', '#9333ea'];

        return $palette[abs(crc32($seed)) % count($palette)];
    }
}
