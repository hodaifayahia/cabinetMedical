<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $patient_id
 * @property string $vaccine
 * @property string|null $dose
 * @property string|null $schedule_key
 * @property CarbonImmutable $given_on
 * @property string|null $lot
 * @property string|null $notes
 */
#[Fillable(['cabinet_id', 'public_id', 'patient_id', 'vaccine', 'dose', 'schedule_key', 'given_on', 'lot', 'notes', 'created_by'])]
class PatientVaccination extends Model
{
    use BelongsToCabinet;

    protected static function booted(): void
    {
        static::creating(function (self $vaccination): void {
            $vaccination->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['given_on' => 'immutable_date'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }
}
