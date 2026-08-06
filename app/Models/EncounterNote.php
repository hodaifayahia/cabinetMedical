<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<array-key, mixed>|null $content_json
 */
class EncounterNote extends Model
{
    /** @use HasFactory<Factory<EncounterNote>> */
    use HasFactory;

    protected $fillable = [
        'encounter_id',
        'section',
        'content_json',
        'content_text',
        'author_id',
        'revision_number',
    ];

    protected $casts = [
        'content_json' => 'array',
    ];

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
