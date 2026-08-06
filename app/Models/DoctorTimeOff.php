<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DoctorTimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $is_all_day
 */
#[Fillable([
    'doctor_id',
    'starts_at',
    'ends_at',
    'is_all_day',
    'reason',
    'notes',
])]
class DoctorTimeOff extends Model
{
    /** @use HasFactory<DoctorTimeOffFactory> */
    use HasFactory;

    /**
     * The table name is singular and does not match Eloquent's plural guess.
     */
    protected $table = 'doctor_time_off';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_all_day' => 'boolean',
        ];
    }

    protected static function newFactory(): DoctorTimeOffFactory
    {
        return DoctorTimeOffFactory::new();
    }

    /**
     * @return BelongsTo<DoctorProfile, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_id');
    }
}
