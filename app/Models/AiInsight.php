<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored AI result (patient analysis, document analysis) so the doctor can
 * reopen it for free, and so later patient analyses can build on what each
 * document was found to contain.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $document_id
 * @property string $kind
 * @property array<string, mixed> $content
 * @property int|null $created_by
 */
#[Fillable(['patient_id', 'document_id', 'kind', 'content', 'created_by'])]
class AiInsight extends Model
{
    use BelongsToCabinet;

    public const KIND_PATIENT_ANALYSIS = 'patient_analysis';

    public const KIND_DOCUMENT_ANALYSIS = 'document_analysis';

    protected function casts(): array
    {
        return [
            'content' => 'array',
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
