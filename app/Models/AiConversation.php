<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A copilot conversation during a consultation. Kept whole, proposed actions
 * included, as the audit trail of what the assistant suggested.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $consultation_id
 * @property list<array<string, mixed>> $messages
 * @property int|null $created_by
 */
#[Fillable(['patient_id', 'consultation_id', 'messages', 'created_by'])]
class AiConversation extends Model
{
    use BelongsToCabinet;

    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }
}
