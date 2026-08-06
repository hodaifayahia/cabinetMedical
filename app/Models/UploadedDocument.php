<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $patient_id
 * @property string $upload_session_id
 * @property string $original_name
 * @property string $stored_name
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property CarbonImmutable|null $uploaded_at
 */
#[Fillable([
    'upload_session_id',
    'patient_id',
    'document_id',
    'original_name',
    'stored_name',
    'disk',
    'path',
    'mime_type',
    'size',
    'sha256',
    'status',
    'uploaded_at',
    'reviewed_by',
    'reviewed_at',
])]
class UploadedDocument extends Model
{
    use HasUuids;

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UploadSession, $this> */
    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
