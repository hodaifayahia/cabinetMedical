<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'currency',
    'vat_rate',
    'default_consultation_fee_minor',
    'receipt_prefix',
    'fiscal_year_start',
])]
class AccountingSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vat_rate' => 'integer',
            'default_consultation_fee_minor' => 'integer',
        ];
    }

    /**
     * Resolve (or create) the single accounting settings row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
