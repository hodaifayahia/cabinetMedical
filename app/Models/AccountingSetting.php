<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'cabinet_id',
    'currency',
    'vat_rate',
    'default_consultation_fee_minor',
    'receipt_prefix',
    'fiscal_year_start',
])]
class AccountingSetting extends Model
{
    use BelongsToCabinet;

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
     * Resolve (or create) the accounting settings shared by one cabinet.
     */
    public static function current(?int $cabinetId = null): self
    {
        $resolvedCabinetId = $cabinetId ?? auth()->user()?->cabinet_id;

        return static::query()->firstOrCreate([
            'cabinet_id' => $resolvedCabinetId,
        ], [
            'currency' => 'DA',
            'vat_rate' => 0,
            'receipt_prefix' => 'FACT-',
            'fiscal_year_start' => '01-01',
        ]);
    }
}
