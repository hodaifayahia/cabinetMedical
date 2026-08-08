<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $measured_at
 */
#[Fillable([
    'patient_id',
    'measured_at',
    'weight_kg',
    'height_cm',
    'bmi',
    'waist_cm',
    'head_cm',
    'notes',
    'created_by',
])]
class PatientMeasurement extends Model
{
    use BelongsToCabinet;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
