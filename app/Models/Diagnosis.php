<?php

namespace App\Models;

use App\Enums\DiagnosisStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DiagnosisStatus $status
 */
class Diagnosis extends Model
{
    /** @use HasFactory<Factory<Diagnosis>> */
    use HasFactory;

    protected $fillable = [
        'encounter_id',
        'code',
        'code_system',
        'display_label',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'status' => DiagnosisStatus::class,
    ];

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
