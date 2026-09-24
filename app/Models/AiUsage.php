<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a cabinet's AI credit ledger: a spend, a refund after a failed
 * call, or a recharge made by the platform admin.
 *
 * Written only by AiCreditLedger with an explicit cabinet_id, so it carries no
 * tenant scope: the admin panel reads every cabinet's lines.
 *
 * @property int $id
 * @property int|null $cabinet_id
 * @property int|null $user_id
 * @property string $feature
 * @property int $credits
 * @property int $balance_after
 * @property string $status
 * @property string|null $model
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property string|null $note
 */
#[Fillable([
    'cabinet_id',
    'user_id',
    'feature',
    'credits',
    'balance_after',
    'status',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'note',
])]
class AiUsage extends Model
{
    public const STATUS_CHARGED = 'charged';

    public const STATUS_ADJUSTED = 'adjusted';

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'balance_after' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
