<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $expires_at
 * @property list<string> $allowed_mime_types
 * @property int $maximum_files
 * @property int $maximum_individual_bytes
 * @property int $maximum_total_bytes
 * @property int|null $patient_id
 * @property string $mode
 * @property string $status
 */
#[Fillable([
    'public_selector',
    'public_token_hash',
    'mode',
    'purpose',
    'patient_id',
    'created_by',
    'expires_at',
    'maximum_files',
    'maximum_individual_bytes',
    'maximum_total_bytes',
    'allowed_mime_types',
    'status',
    'source_ip',
    'user_agent',
    'completed_at',
])]
class UploadSession extends Model
{
    use BelongsToCabinet, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'maximum_files' => 'integer',
            'maximum_individual_bytes' => 'integer',
            'maximum_total_bytes' => 'integer',
            'allowed_mime_types' => 'array',
        ];
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_UPLOADING], true)
            && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<UploadedDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }
}
