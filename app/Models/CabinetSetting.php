<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cabinet_id',
    'name',
    'address',
    'city',
    'phone',
    'email',
    'logo_path',
    'currency_code',
    'timezone',
    'default_appointment_duration',
    'default_consultation_fee_minor',
    'receipt_footer',
    'prescription_footer',
    'low_stock_threshold',
    'expiry_warning_days',
])]
class CabinetSetting extends Model
{
    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * Resolve the settings row for the current cabinet.
     *
     * In an authenticated request this resolves (and lazily creates) the
     * settings row for the signed-in user's cabinet. Outside a request context
     * — console commands, queued jobs, seeders — it falls back to the first
     * settings row, preserving the historical singleton behaviour so existing
     * call sites keep working. When a cabinet is explicitly provided it is used
     * directly, which the platform back office relies on.
     */
    public static function current(?Cabinet $cabinet = null): self
    {
        $cabinet ??= static::resolveCabinet();

        if ($cabinet instanceof Cabinet) {
            return static::query()->firstOrCreate(
                ['cabinet_id' => $cabinet->getKey()],
                [...static::defaults(), 'cabinet_id' => $cabinet->getKey()],
            );
        }

        // No cabinet in scope (console/seed/legacy single install): reuse the
        // earliest row, or materialise a global one from configuration.
        return static::query()->orderBy('id')->firstOr(
            fn (): self => static::query()->create(static::defaults()),
        );
    }

    private static function resolveCabinet(): ?Cabinet
    {
        if (! app()->bound('auth') || ! auth()->hasUser()) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user->cabinet : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => (string) config('clinic.name', config('app.name')),
            'address' => config('clinic.address') ?: null,
            'city' => null,
            'phone' => config('clinic.phone') ?: null,
            'email' => config('clinic.email') ?: null,
            'logo_path' => null,
            'currency_code' => (string) config('clinic.currency.code', 'DZD'),
            'timezone' => (string) config('clinic.timezone', config('app.timezone', 'UTC')),
            'default_appointment_duration' => (int) config('clinic.appointments.default_duration', 30),
            'default_consultation_fee_minor' => 0,
            'low_stock_threshold' => (int) config('clinic.inventory.low_stock_threshold', 10),
            'expiry_warning_days' => (int) config('clinic.inventory.expiry_warning_days', 30),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_appointment_duration' => 'integer',
            'default_consultation_fee_minor' => 'integer',
            'low_stock_threshold' => 'integer',
            'expiry_warning_days' => 'integer',
        ];
    }
}
