<?php

namespace App\Models;

use App\Enums\AntecedentCategory;
use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AntecedentCategory $category
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $ended_on
 * @property bool $is_active
 */
class PatientAntecedent extends Model
{
    /** @use HasFactory<Factory<PatientAntecedent>> */
    use BelongsToCabinet, HasFactory;

    protected $fillable = [
        'patient_id',
        'category',
        'description',
        'started_on',
        'ended_on',
        'is_active',
        'source_encounter_id',
        'created_by',
    ];

    protected $casts = [
        'category' => AntecedentCategory::class,
        'started_on' => 'date',
        'ended_on' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function sourceEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'source_encounter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
