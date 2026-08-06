<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $filename
 * @property string|null $local_path
 * @property string|null $remote_file_id
 * @property string|null $drive_upload_status
 * @property int $drive_upload_bytes
 * @property int $drive_upload_attempts
 * @property string|null $drive_upload_failure_code
 * @property CarbonImmutable|null $drive_upload_cancel_requested_at
 * @property CarbonImmutable|null $drive_upload_updated_at
 * @property int|null $size
 * @property string|null $sha256
 * @property string $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $created_by
 */
#[Fillable([
    'filename',
    'disk',
    'local_path',
    'remote_file_id',
    'drive_upload_status',
    'drive_upload_bytes',
    'drive_upload_attempts',
    'drive_upload_failure_code',
    'drive_upload_cancel_requested_at',
    'drive_upload_updated_at',
    'size',
    'sha256',
    'schema_version',
    'application_version',
    'status',
    'started_at',
    'completed_at',
    'failure_message',
    'created_by',
])]
class BackupRecord extends Model
{
    use HasUuids;

    public const DRIVE_UPLOAD_QUEUED = 'queued';

    public const DRIVE_UPLOAD_UPLOADING = 'uploading';

    public const DRIVE_UPLOAD_RETRYING = 'retrying';

    public const DRIVE_UPLOAD_CANCEL_REQUESTED = 'cancel_requested';

    public const DRIVE_UPLOAD_CANCELLED = 'cancelled';

    public const DRIVE_UPLOAD_COMPLETED = 'completed';

    public const DRIVE_UPLOAD_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'drive_upload_bytes' => 'integer',
            'drive_upload_attempts' => 'integer',
            'schema_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'drive_upload_cancel_requested_at' => 'immutable_datetime',
            'drive_upload_updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
