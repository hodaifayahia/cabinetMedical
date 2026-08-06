<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable|null $last_health_check_at */
#[Fillable([
    'provider',
    'mode',
    'tunnel_id',
    'tunnel_name',
    'hostname',
    'encrypted_tunnel_token',
    'executable_path',
    'service_installed',
    'desired_state',
    'runtime_state',
    'last_started_at',
    'last_stopped_at',
    'last_health_check_at',
    'last_error',
])]
#[Hidden(['encrypted_tunnel_token'])]
class TunnelSetting extends Model
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
            'encrypted_tunnel_token' => 'encrypted',
            'service_installed' => 'boolean',
            'last_started_at' => 'immutable_datetime',
            'last_stopped_at' => 'immutable_datetime',
            'last_health_check_at' => 'immutable_datetime',
        ];
    }
}
