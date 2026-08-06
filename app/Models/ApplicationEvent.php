<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'severity', 'message', 'context', 'occurred_at'])]
class ApplicationEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $event,
        string $severity = 'info',
        ?string $message = null,
        array $context = [],
    ): self {
        return static::query()->create([
            'event' => $event,
            'severity' => $severity,
            'message' => $message === null ? null : AuditLog::redactSensitiveText($message),
            'context' => AuditLog::redactSensitiveMetadata($context),
            'occurred_at' => now(),
        ]);
    }
}
