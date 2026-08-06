<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
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
     * Resolve the single cabinet settings row, creating it from configuration
     * defaults on first access. The cabinet is a singleton by design.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], static::defaults());
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
