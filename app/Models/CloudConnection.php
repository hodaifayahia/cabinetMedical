<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $status
 * @property CarbonImmutable|null $last_connected_at
 */
#[Fillable([
    'provider',
    'account_email',
    'encrypted_access_token',
    'encrypted_refresh_token',
    'token_expires_at',
    'folder_id',
    'folder_name',
    'status',
    'last_connected_at',
    'last_error',
])]
#[Hidden(['encrypted_access_token', 'encrypted_refresh_token'])]
class CloudConnection extends Model
{
    /** @return Attribute<string|null, string|null> */
    protected function lastError(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => $value === null
                ? null
                : mb_substr(AuditLog::redactSensitiveText($value), 0, 1000),
        );
    }

    protected function casts(): array
    {
        return [
            'encrypted_access_token' => 'encrypted',
            'encrypted_refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'last_connected_at' => 'immutable_datetime',
        ];
    }
}
