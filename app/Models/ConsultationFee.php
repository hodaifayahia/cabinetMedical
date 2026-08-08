<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'amount_minor', 'category', 'is_active'])]
class ConsultationFee extends Model
{
    use BelongsToCabinet;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
