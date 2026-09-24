<?php

namespace App\Models;

use Database\Factories\WilayaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $code
 * @property string $name_fr
 * @property string $name_ar
 * @property bool $is_active
 */
#[Fillable(['code', 'name_fr', 'name_ar', 'is_active'])]
class Wilaya extends Model
{
    /** @use HasFactory<WilayaFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function newFactory(): WilayaFactory
    {
        return WilayaFactory::new();
    }

    /**
     * @return HasMany<Baladiya, $this>
     */
    public function baladiyas(): HasMany
    {
        return $this->hasMany(Baladiya::class, 'wilaya_code', 'code');
    }
}
