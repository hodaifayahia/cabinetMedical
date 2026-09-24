<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ECG tracing in the patient's dossier.
 *
 * Three layers are kept apart on purpose: `measurements` come from the
 * software reading the trace, `analysis` from the AI, and `doctor_conclusion`
 * is the only one that counts clinically once `status` is validated.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $consultation_id
 * @property string $title
 * @property CarbonImmutable|null $recorded_at
 * @property string $file_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property array<string, mixed>|null $measurements
 * @property array<string, mixed>|null $analysis
 * @property list<array{role: string, content: string, at?: string}>|null $conversation
 * @property string|null $doctor_conclusion
 * @property string $status
 * @property int|null $validated_by
 * @property CarbonImmutable|null $validated_at
 * @property int|null $created_by
 */
#[Fillable([
    'patient_id',
    'consultation_id',
    'title',
    'recorded_at',
    'file_path',
    'original_filename',
    'mime_type',
    'file_size',
    'measurements',
    'analysis',
    'conversation',
    'doctor_conclusion',
    'status',
    'validated_by',
    'validated_at',
    'created_by',
])]
class EcgRecord extends Model
{
    use BelongsToCabinet;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_date',
            'measurements' => 'array',
            'analysis' => 'array',
            'conversation' => 'array',
            'file_size' => 'integer',
            'validated_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }
}
