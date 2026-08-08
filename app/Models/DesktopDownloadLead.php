<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $cabinet_name
 * @property string $specialization
 * @property Carbon|null $downloaded_at
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'cabinet_name',
    'specialization',
    'downloaded_at',
])]
final class DesktopDownloadLead extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }
}
