<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $consultation_id
 * @property int $patient_id
 * @property string $code
 * @property string $label
 */
#[Fillable(['cabinet_id', 'consultation_id', 'patient_id', 'code', 'label'])]
class ConsultationDiagnosis extends Model
{
    use BelongsToCabinet;

    /** @return BelongsTo<Consultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
