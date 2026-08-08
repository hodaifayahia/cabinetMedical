<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'user_id',
    'action',
    'subject_type',
    'subject_id',
    'metadata',
    'ip_address',
    'user_agent',
])]
class AuditLog extends Model
{
    use BelongsToCabinet;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit log records are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Audit log records are immutable.');
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?int $userId = null,
    ): self {
        $request = app()->bound('request') ? request() : null;
        $resolvedUserId = $userId ?? auth()->id();
        $cabinetId = match (true) {
            $subject instanceof Cabinet => $subject->getKey(),
            $subject?->getAttribute('cabinet_id') !== null => $subject->getAttribute('cabinet_id'),
            auth()->user()?->cabinet_id !== null => auth()->user()->cabinet_id,
            $resolvedUserId !== null => User::query()->whereKey($resolvedUserId)->value('cabinet_id'),
            default => null,
        };

        $auditLog = new static([
            'user_id' => $resolvedUserId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'metadata' => self::redactSensitiveMetadata($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
        $auditLog->forceFill(['cabinet_id' => $cabinetId])->save();

        return $auditLog;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function redactSensitiveMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (preg_match('/(?:password|passphrase|pin|token|verifier|secret|serial|fingerprint|private[_-]?key|health[_-]?key|backup[_-]?(?:key|phrase)|oauth[_-]?state|authorization[_-]?code|license[_-]?code|(?:signed|license)[_-]?certificate)/i', (string) $key) === 1) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = self::redactSensitiveMetadata($value);
            } elseif (is_string($value)) {
                $metadata[$key] = self::redactSensitiveText($value);
            }
        }

        return $metadata;
    }

    public static function redactSensitiveText(string $text): string
    {
        $patterns = [
            '/(--token(?:=|\s+))[^\s]+/i' => '$1[redacted]',
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i' => '$1[redacted]',
            '/((?:Authorization|Proxy-Authorization|Cookie|Set-Cookie|X-MediSmart-Health-Key)\s*:\s*)[^\r\n]*/i' => '$1[redacted]',
            '/((?:password|passphrase|pin|token|verifier|secret|serial|fingerprint|private[_-]?key|health[_-]?key|backup[_-]?(?:key|phrase)|oauth[_-]?state|authorization[_-]?code|license[_-]?code|(?:signed|license)[_-]?certificate)\s*[=:]\s*)[^\s,;]+/i' => '$1[redacted]',
            '/([?&#](?:access_token|refresh_token|client_secret|token|key|secret|code|state|password|passphrase|pin|verifier|serial)=)[^&#\s]+/i' => '$1[redacted]',
            '#(https?://)[^/\s:@]+:[^/@\s]+@#i' => '$1[redacted]:[redacted]@',
            '/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}(?![A-Za-z0-9_-])/' => '[redacted]',
            '#(/upload/)[A-Za-z0-9_-]{16,}#' => '$1[redacted]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
