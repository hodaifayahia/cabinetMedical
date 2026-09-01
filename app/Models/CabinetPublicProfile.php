<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Database\Factories\CabinetPublicProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The public directory listing of a cabinet. Mobile discovery only surfaces
 * cabinets whose profile is listed and whose cabinet is active.
 *
 * @property int|null $cabinet_id
 * @property bool $is_listed
 * @property int|null $baladiya_id
 * @property array<int, string>|null $phones
 * @property array<int, string>|null $photos
 */
#[Fillable([
    'is_listed',
    'about',
    'address',
    'baladiya_id',
    'phones',
    'latitude',
    'longitude',
    'photos',
])]
class CabinetPublicProfile extends Model
{
    /** @use HasFactory<CabinetPublicProfileFactory> */
    use BelongsToCabinet, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_listed' => 'boolean',
            'phones' => 'array',
            'photos' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function newFactory(): CabinetPublicProfileFactory
    {
        return CabinetPublicProfileFactory::new();
    }

    /**
     * @return BelongsTo<Baladiya, $this>
     */
    public function baladiya(): BelongsTo
    {
        return $this->belongsTo(Baladiya::class);
    }
}
