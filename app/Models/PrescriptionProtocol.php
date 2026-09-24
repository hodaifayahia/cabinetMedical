<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A saved ordonnance set the cabinet reuses.
 *
 * @property string $name
 * @property list<array{medication: string, dosage?: string|null, duration?: string|null, instructions?: string|null}> $items
 * @property string|null $notes
 * @property int $uses
 */
#[Fillable(['cabinet_id', 'public_id', 'name', 'items', 'notes', 'uses', 'created_by'])]
class PrescriptionProtocol extends Model
{
    use BelongsToCabinet;

    protected static function booted(): void
    {
        static::creating(function (self $protocol): void {
            $protocol->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['items' => 'array', 'uses' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'items' => array_values($this->items),
            'notes' => $this->notes,
            'uses' => $this->uses,
        ];
    }
}
