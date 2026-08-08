<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Database\Factories\MedicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'dci',
    'form',
    'dosage',
    'notes',
    'is_active',
])]
class Medication extends Model
{
    /** @use HasFactory<MedicationFactory> */
    use BelongsToCabinet, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): MedicationFactory
    {
        return MedicationFactory::new();
    }

    /**
     * Filter by a free-text search across name, generic name, and form.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('dci', 'like', $like)
                ->orWhere('form', 'like', $like);
        });
    }
}
