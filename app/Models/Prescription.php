<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $prescribed_at
 * @property list<array<string, mixed>>|null $items
 */
#[Fillable([
    'patient_id',
    'consultation_id',
    'document_id',
    'prescribed_at',
    'items',
    'notes',
    'created_by',
])]
class Prescription extends Model
{
    use BelongsToCabinet;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prescribed_at' => 'immutable_datetime',
            'items' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
