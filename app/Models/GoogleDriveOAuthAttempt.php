<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $state_sha256
 * @property string|null $encrypted_pkce_verifier
 * @property string $redirect_uri
 * @property int $cabinet_setting_id
 * @property int $actor_id
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_code
 */
#[Fillable([
    'state_sha256',
    'encrypted_pkce_verifier',
    'redirect_uri',
    'cabinet_setting_id',
    'actor_id',
    'status',
    'expires_at',
    'consumed_at',
    'failed_at',
    'failure_code',
])]
#[Hidden(['state_sha256', 'encrypted_pkce_verifier', 'redirect_uri'])]
final class GoogleDriveOAuthAttempt extends Model
{
    use HasUuids;

    protected $table = 'google_drive_oauth_attempts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** @return BelongsTo<CabinetSetting, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(CabinetSetting::class, 'cabinet_setting_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_pkce_verifier' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
