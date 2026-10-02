<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $cabinet_name
 * @property string $specialization
 * @property int|null $cabinet_id
 * @property Carbon|null $downloaded_at
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'cabinet_name',
    'specialization',
    'cabinet_id',
    'downloaded_at',
])]
final class DesktopDownloadLead extends Model
{
    // No HasFactory: there is no DesktopDownloadLeadFactory and nothing calls
    // ::factory(), so the trait only left an unparameterised generic behind.
    use HasUuids;

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }
}
