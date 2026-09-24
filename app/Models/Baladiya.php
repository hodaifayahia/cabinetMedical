<?php

namespace App\Models;

use Database\Factories\BaladiyaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $wilaya_code
 * @property string $name_fr
 * @property string $name_ar
 */
#[Fillable(['wilaya_code', 'name_fr', 'name_ar', 'is_active'])]
class Baladiya extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @use HasFactory<BaladiyaFactory> */
    use HasFactory;

    protected static function newFactory(): BaladiyaFactory
    {
        return BaladiyaFactory::new();
    }

    /**
     * @return BelongsTo<Wilaya, $this>
     */
    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class, 'wilaya_code', 'code');
    }
}
