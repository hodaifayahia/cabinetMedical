<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DevicePushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push-capable device registered by a mobile user. Phase 1 stores the
 * token only; delivery ships in a later phase.
 *
 * @property int $user_id
 * @property string $token
 * @property string|null $platform
 * @property CarbonImmutable|null $last_seen_at
 */
#[Fillable(['user_id', 'token', 'platform', 'last_seen_at'])]
class DevicePushToken extends Model
{
    /** @use HasFactory<DevicePushTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DevicePushTokenFactory
    {
        return DevicePushTokenFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
