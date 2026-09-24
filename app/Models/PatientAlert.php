<?php

namespace App\Models;

use App\Enums\PatientAlertType;
use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One entry of a patient's safety list: an allergy, a chronic condition or
 * a long-term treatment.
 *
 * @property int $patient_id
 * @property PatientAlertType $type
 * @property string $label
 * @property string|null $severity
 * @property string|null $details
 * @property CarbonImmutable|null $since
 * @property bool $is_active
 */
#[Fillable([
    'cabinet_id',
    'public_id',
    'patient_id',
    'type',
    'label',
    'severity',
    'details',
    'since',
    'is_active',
    'created_by',
])]
class PatientAlert extends Model
{
    use BelongsToCabinet;

    public const SEVERITIES = ['mild' => 'Légère', 'moderate' => 'Modérée', 'severe' => 'Sévère (anaphylaxie)'];

    protected static function booted(): void
    {
        static::creating(function (self $alert): void {
            $alert->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PatientAlertType::class,
            'since' => 'immutable_date',
            'is_active' => 'boolean',
        ];
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
