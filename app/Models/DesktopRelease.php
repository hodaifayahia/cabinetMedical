<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published desktop build.
 *
 * A release is not cabinet scoped: every installation of the software reads
 * the same one, so this model deliberately does not use BelongsToCabinet.
 *
 * @property string $version
 * @property string $channel
 * @property string $platform
 * @property string|null $notes
 * @property string $installer_path
 * @property string $installer_name
 * @property int $installer_size
 * @property string $installer_sha256
 * @property string $signature
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by_user_id
 */
#[Fillable([
    'version',
    'channel',
    'platform',
    'notes',
    'installer_path',
    'installer_name',
    'installer_size',
    'installer_sha256',
    'signature',
    'published_at',
    'published_by_user_id',
])]
class DesktopRelease extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'installer_size' => 'integer',
        ];
    }

    /**
     * The release the updater and the public download should both serve.
     *
     * Newest published wins. An unpublished row is a draft: it exists, it has
     * its artifact stored, and nobody is offered it yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query, string $platform = 'windows-x86_64', string $channel = 'stable'): Builder
    {
        return $query
            ->where('platform', $platform)
            ->where('channel', $channel)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Absolute path to the stored artifact.
     */
    public function installerFullPath(): string
    {
        return storage_path('app/private/desktop/releases/'.$this->installer_path);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
