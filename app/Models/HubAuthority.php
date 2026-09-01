<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Which Hub currently holds clinical write authority for a cabinet.
 *
 * @property int $id
 * @property int $cabinet_id
 * @property string $hub_id
 * @property int $authority_epoch
 * @property Carbon $adopted_at
 * @property string $adopted_reason
 * @property int|null $adopted_by_user_id
 * @property string|null $previous_hub_id
 */
#[Fillable([
    'cabinet_id',
    'hub_id',
    'authority_epoch',
    'adopted_at',
    'adopted_reason',
    'adopted_by_user_id',
    'previous_hub_id',
])]
class HubAuthority extends Model
{
    /**
     * A Hub is provisioned for the first time.
     */
    public const REASON_PROVISIONED = 'provisioned';

    /**
     * The previous Hub was lost and a restored backup was adopted on new
     * hardware. This is the case that raises the epoch and fences the old box.
     */
    public const REASON_REPLACEMENT = 'replacement';

    protected $table = 'hub_authority';

    protected function casts(): array
    {
        return [
            'authority_epoch' => 'integer',
            'adopted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adoptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adopted_by_user_id');
    }

    /**
     * Whether this record names the given Hub as the write authority.
     */
    public function isHeldBy(?string $hubId): bool
    {
        return $hubId !== null && hash_equals($this->hub_id, $hubId);
    }
}
