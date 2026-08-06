<?php

namespace App\Models;

use Database\Factories\DoctorOpenMonthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $year
 * @property int $month
 * @property bool $is_open
 */
#[Fillable([
    'doctor_id',
    'year',
    'month',
    'is_open',
    'note',
])]
class DoctorOpenMonth extends Model
{
    /** @use HasFactory<DoctorOpenMonthFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    protected static function newFactory(): DoctorOpenMonthFactory
    {
        return DoctorOpenMonthFactory::new();
    }

    /**
     * @return BelongsTo<DoctorProfile, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_id');
    }
}
