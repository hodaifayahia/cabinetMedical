<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How far this installation has synchronised with one remote endpoint.
 *
 * Sync is resumable: an interrupted run leaves the cursors where they were, and
 * the next run continues from there rather than replaying the whole history.
 *
 * @property int $cabinet_id
 * @property string $endpoint
 * @property string $endpoint_sha256
 * @property string $stream
 * @property int $pull_cursor
 * @property int $push_cursor
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_failed_at
 * @property string|null $last_error
 * @property bool $last_failure_offline
 */
#[Fillable([
    'cabinet_id',
    'endpoint',
    'endpoint_sha256',
    'stream',
    'pull_cursor',
    'push_cursor',
    'last_synced_at',
    'last_failed_at',
    'last_error',
    'last_failure_offline',
])]
class SyncState extends Model
{
    use BelongsToCabinet;

    public const STREAM_APPOINTMENTS = 'appointments';

    protected function casts(): array
    {
        return [
            'pull_cursor' => 'integer',
            'push_cursor' => 'integer',
            'last_synced_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
            'last_failure_offline' => 'boolean',
        ];
    }

    /**
     * Find or start the cursor record for one cabinet/endpoint/stream triple.
     */
    public static function forEndpoint(int $cabinetId, string $endpoint, string $stream): self
    {
        return static::withoutCabinetScope()->firstOrCreate(
            [
                'cabinet_id' => $cabinetId,
                'endpoint_sha256' => self::hashEndpoint($endpoint),
                'stream' => $stream,
            ],
            [
                'endpoint' => $endpoint,
                'pull_cursor' => 0,
                'push_cursor' => 0,
            ],
        );
    }

    public static function hashEndpoint(string $endpoint): string
    {
        // Trailing-slash and case differences describe the same origin; without
        // normalising, one cabinet would keep two competing cursor rows.
        return hash('sha256', rtrim(mb_strtolower(trim($endpoint)), '/'));
    }

    public function markSynced(): void
    {
        $this->forceFill([
            'last_synced_at' => now(),
            'last_failed_at' => null,
            'last_error' => null,
            'last_failure_offline' => false,
        ])->save();
    }

    /**
     * @param  bool  $offline  The online service could not be reached at all:
     *                         the expected state of a local-first poste, which
     *                         the next scheduled run simply retries.
     */
    public function markFailed(string $error, bool $offline = false): void
    {
        $this->forceFill([
            'last_failed_at' => now(),
            // Bounded: a remote error body must not grow this row without limit.
            'last_error' => mb_substr($error, 0, 1000),
            'last_failure_offline' => $offline,
        ])->save();
    }
}
