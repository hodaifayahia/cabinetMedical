<?php

namespace App\Models;

use App\Enums\PatientRelation;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One direction of a family link between two dossiers: $relative is the
 * $relation of $patient (« Ali est le frère de Sara » is stored on Sara's
 * side as relation = brother). The opposite row always exists too.
 *
 * @property int $patient_id
 * @property int $relative_patient_id
 * @property PatientRelation $relation
 * @property string $public_id
 * @property-read Patient|null $patient
 * @property-read Patient|null $relative
 */
#[Fillable([
    'cabinet_id',
    'public_id',
    'patient_id',
    'relative_patient_id',
    'relation',
    'created_by',
])]
class PatientRelative extends Model
{
    use BelongsToCabinet;

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relation' => PatientRelation::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function relative(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'relative_patient_id');
    }
}
