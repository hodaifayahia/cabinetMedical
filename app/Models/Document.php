<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $file_size
 * @property int $file_version
 */
#[Fillable([
    'patient_id',
    'consultation_id',
    'category',
    'title',
    'template_key',
    'paper_size',
    'content',
    'file_path',
    'original_filename',
    'mime_type',
    'file_size',
    'file_version',
    'created_by',
])]
class Document extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'file_version' => 'integer',
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
