<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passwordless desktop credential bound to one opaque installation token.
 * Neither the plaintext device token nor the plaintext four-digit PIN is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property int $cabinet_id
 * @property string $device_token_hash
 * @property string $device_name
 * @property string $pin_hash
 * @property int $failed_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_used_at
 */
#[Fillable([
    'user_id',
    'cabinet_id',
    'device_token_hash',
    'device_name',
    'pin_hash',
    'failed_attempts',
    'locked_until',
    'last_used_at',
])]
#[Hidden(['device_token_hash', 'pin_hash'])]
class DesktopPinCredential extends Model
{
    use BelongsToCabinet;

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
