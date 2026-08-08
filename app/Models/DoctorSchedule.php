<?php

namespace App\Models;

use App\Enums\Weekday;
use App\Models\Concerns\BelongsToCabinet;
use Database\Factories\DoctorScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Weekday $day_of_week
 * @property int $slot_duration
 * @property bool $is_active
 */
#[Fillable([
    'doctor_id',
    'day_of_week',
    'starts_at',
    'ends_at',
    'slot_duration',
    'is_active',
])]
class DoctorSchedule extends Model
{
    /** @use HasFactory<DoctorScheduleFactory> */
    use BelongsToCabinet, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => Weekday::class,
            'slot_duration' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): DoctorScheduleFactory
    {
        return DoctorScheduleFactory::new();
    }

    /**
     * @return BelongsTo<DoctorProfile, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_id');
    }
}
